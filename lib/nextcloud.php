<?php

/**
 * @author Jason F. Irwin
 *
 * Class contains the rules and methods called to manage NextCloud Resources
 */
require_once( LIB_DIR . '/functions.php');

class Nextcloud {
    var $settings;

    function __construct( $Items ) {
        $this->settings = $Items;
    }

    /** ********************************************************************* *
     *  Perform Action Blocks
     ** ********************************************************************* */
    public function performAction() {
        $ReqType = NoNull(strtolower($this->settings['ReqType']));
        $rVal = false;

        // Check the User Token is Valid
        if ( !$this->settings['_logged_in']) {
            $this->_setMetaMessage("You Need to Log In First", 401);
            return false;
        }

        // Perform the Action
        switch ( $ReqType ) {
            case 'get':
                $rVal = $this->_performGetAction();
                break;

            case 'post':
                $rVal = $this->_performPostAction();
                break;

            case 'delete':
                $rVal = $this->_performDeleteAction();
                break;

            default:
                // Do Nothing
        }

        // Return The Array of Data or an Unhappy Boolean
        return $rVal;
    }

    private function _performGetAction() {
        $Activity = NoNull(strtolower($this->settings['PgSub1']));
        if ( nullInt($this->settings['PgSub1']) > 0 ) { $Activity = 'list'; }
        $rVal = false;

        switch ( $Activity ) {
            case 'test':
                $rVal = $this->_testClass();
                break;

            default:
                // Do Nothing
        }

        // Return the Array of Data or an Unhappy Boolean
        return $rVal;
    }

    private function _performPostAction() {
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        $rVal = false;

        switch ( $Activity ) {
            default:
                // Do Nothing
        }

        // Return the Array of Data or an Unhappy Boolean
        return $rVal;
    }

    private function _performDeleteAction() {
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        if ( nullInt($this->settings['PgSub1']) > 0 ) { $Activity = 'scrub'; }
        $rVal = false;

        switch ( $Activity ) {
            default:
                // Do Nothing
        }

        // Return the Array of Data or an Unhappy Boolean
        return $rVal;
    }

    /**
     *  Function Returns the Response Type (HTML / XML / JSON / etc.)
     */
    public function getResponseType() {
        return NoNull($this->settings['type'], 'application/json');
    }

    /**
     *  Function Returns the Reponse Code (200 / 201 / 400 / 401 / etc.)
     */
    public function getResponseCode() {
        return nullInt($this->settings['status'], 200);
    }

    /**
     *  Function Returns any Error Messages that might have been raised
     */
    public function getResponseMeta() {
        return is_array($this->settings['errors']) ? $this->settings['errors'] : false;
    }

    /** ********************************************************************* *
     *  Public Functions
     ** ********************************************************************* */

    /** ********************************************************************* *
     *  File Management Functions
     ** ********************************************************************* */
    private function _testClass() {
        $sqlStr = "SELECT fc.`fileid`, fc.`path`, fc.`name`, fc.`size`, fc.`mtime`, fc.`storage_mtime` FROM `oc_filecache` fc" .
                  " WHERE `is_processed` = 'N' and `path` LIKE 'files/Photos/%' and `mimetype` = 8" .
                  " ORDER BY fc.`fileid`;";
        $rslt = doSQLQuery( $sqlStr, false, 'nextcloud' );
        if ( is_array($rslt) ) {
            require_once(LIB_DIR . '/images.php');
            $data = array();
            $sqlStr = '';
            $fcnt = 0;
            $cnt = 0;

            // Loop Through the Files Looking for Differences
            foreach ( $rslt as $Row ) {
                $fullPath = '/var/www/files/nextcloud/data/matigo/' . NoNull($Row['path']);
                $fileID = nullInt($Row['fileid']);

                if ( file_exists($fullPath) ) {
                    $img = new Images();
                    $img->load($fullPath);
                    $imgMeta = $img->getPhotoMeta();
                    
                    $propTime = nullInt($Row['mtime']);
                    $propName = NoNull($Row['name']);
                    
                    if ( NoNull($imgMeta['datetime']) != '' ) {
                        $img_ts = strtotime($imgMeta['datetime']);
                        if ( $img_ts >= 10000000 ) {
                            $propTime = strtotime("-9 hour", $img_ts);
                            $propName = date("Y-m-d_His", strtotime($imgMeta['datetime']));
                        }
                    }

                    // Update the DB Where Required
                    if ( $propTime != nullInt($Row['mtime']) || $propName != NoNull($Row['name']) ) {
                        if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                        $sqlStr .= "UPDATE `oc_filecache` SET `name` = '" . sqlScrub($propName) . "'," .
                                                            " `mtime` = CASE WHEN $propTime > 0 THEN $propTime ELSE `mtime` END" .
                                   " WHERE `fileid` = $fileID;";

                        $data[] = array( 'file' => $fullPath,
                                         'name' => $propName,
                                         'time' => $propTime
                                        );
                    }
                    unset($img);
                }

                // Mark the file as Processed, even if Nothing Was Done
                if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                $sqlStr .= "UPDATE `oc_filecache` SET `is_processed` = 'Y'" .
                           " WHERE `fileid` = $fileID;";

                // Record the Data to the Database
                if ( $cnt >= 75 ) {
                    $isOK = doSQLExecute($sqlStr, false, 'nextcloud');
                    $sqlStr = '';
                    $cnt = 0;
                }
                
                // Increment the Counters
                $fcnt++;
                $cnt++;
                
                // Exit the Loop After a Set Amount of Work
                if ( $fcnt >= 1750 ) { break; }
            }

            // If There Are Still Things to Record, Do So
            if ( $sqlStr != '' ) { $isOK = doSQLExecute($sqlStr, false, 'nextcloud'); }

            // If We Have Data, Return It
            if ( count($data) > 0 ) { return $data; }
        }

        // If We're Here, Nothing Was Done
        return array();
    }


    /** ********************************************************************* *
     *  File Upload Functions
     ** ********************************************************************* */
    /**
     *  Function Handles File Uploads for a Given Account
     */
    private function _createNewFile() {
        if ( $this->settings['bucket_remain'] < 0 ) { return "Insufficient Storage Remaining"; }
        $list = false;
        $errs = false;

        // Collect the Name of the Files Object
        $items = array();
        foreach ( $_FILES as $Key=>$Value ) {
            $items[] = $Key;
        }

        // Do Not Continue if there are No Files
        if ( count($items) <= 0 ) { return "No Files Found"; }

        // Check to see if there are files and, if so, process them.
        if ( is_array($_FILES) ) {
            require_once(LIB_DIR . '/images.php');
            require_once(LIB_DIR . '/s3.php');

            // If We Should Use Amazon's S3, Activate the Class
            if ( USE_S3 == 1 ) { $s3 = new S3(AWS_ACCESS_KEY, AWS_SECRET_KEY); }

            foreach ( $items as $FileID ) {
                $FileName = NoNull(basename($_FILES[ $FileID ]['name']));
                $FileSize = nullInt($_FILES[ $FileID ]['size']);
                $FileType = NoNull($_FILES[ $FileID ]['type']);
                if ( NoNull($FileType) == '' ) {
                    $ext = $this->_getFileExtension($FileName, $FileType);
                    switch ( strtolower(NoNull($ext)) ) {
                        case 'jpeg':
                        case 'jpg':
                            $FileType = 'image/jpg';
                            break;

                        case 'gif':
                            $FileType = 'image/gif';
                            break;

                        case 'png':
                            $FileType = 'image/png';
                            break;
                    }
                }

                // Validate the File
                $ValidType = $this->_isValidUploadType($FileType, $this->_getFileExtension($FileName, $FileType));

                // Process the File if we have Space in the Bucket, otherwise Record a Size Error
                if ( $ValidType && $FileSize <= (CDN_UPLOAD_LIMIT * 1024 * 1024) && $FileSize <= nullInt($this->settings['bucket_remain']) ) {
                    $this->settings['bucket_remain'] -= $FileSize;
                    $now = time();

                    if ( isset($_FILES[ $FileID ]) ) {
                        $LocalName = md5("$FileName $now") . "." . $this->_getFileExtension($FileName, $FileType);
                        $fullPath = CDN_PATH . '/' . intToAlpha($this->settings['_account_id']) . "/" . strtolower($LocalName);
                        checkDIRExists(CDN_PATH . '/' . intToAlpha($this->settings['_account_id']));

                        $cdnPath = intToAlpha($this->settings['_account_id']) . "/" . strtolower($LocalName);
                        $imgMeta = false;
                        $geoData = false;
                        $isAnimated = false;
                        $isReduced = false;
                        $isGood = false;

                        if ( NoNull($FileName) != "" ) {
                            // Shrink the File If Needs Be
                            if ( $this->_isResizableImage($FileType) ) {
                                $thumbName = md5("$FileName $now") . '_thumb.' . $this->_getFileExtension($FileName, $FileType);
                                $thumbPath = CDN_PATH . '/' . intToAlpha($this->settings['_account_id']) . "/" . strtolower($thumbName);

                                $propName = md5("$FileName $now") . '_medium.' . $this->_getFileExtension($FileName, $FileType);
                                $propPath = CDN_PATH . '/' . intToAlpha($this->settings['_account_id']) . "/" . strtolower($propName);

                                $origName = md5("$FileName $now") . '.' . $this->_getFileExtension($FileName, $FileType);
                                $origPath = CDN_PATH . '/' . intToAlpha($this->settings['_account_id']) . "/" . strtolower($origName);
                                move_uploaded_file($_FILES[ $FileID ]['tmp_name'], $origPath);

                                // Upload the Original Image to S3 if Appropriate
                                if ( USE_S3 == 1 ) {
                                    $s3Path = intToAlpha($this->settings['_account_id']) . strtolower("/$origName");
                                    $s3->putObject($s3->inputFile($origPath, false), CDN_URL, $s3Path, 'public-read');
                                }

                                // Resize the Image
                                $img = new Images();
                                $img->load($origPath);
                                $geoData = $img->getGeolocation();
                                $imgMeta = $img->getPhotoMeta();

                                $imgWidth = $img->getWidth();

                                $isAnimated = $img->is_animated();
                                if ( $isAnimated !== true ) {
                                    if ( $imgWidth > 960 ) {
                                        $img->reduceToWidth(960);
                                        $isGood = $img->save($propPath);
                                        $hasProp = $img->is_reduced();
                                    }
                                    if ( $imgWidth > 480 ) {
                                        $img->reduceToWidth(480);
                                        $isGood = $img->save($thumbPath);
                                        $hasThumb = $img->is_reduced();
                                    }
                                }
                                unset($img);

                            } else {
                                $isGood = move_uploaded_file($_FILES[ $FileID ]['tmp_name'], $fullPath);
                            }

                            $rfu = $this->_recordFileUpload( strtolower($FileName), strtolower($LocalName), $FileSize, strtolower($FileType),
                                                             md5("$FileName $now"), $geoData, $imgMeta, $hasProp, $hasThumb, $isAnimated );
                            if ( is_array($rfu) && count($rfu) > 0 ) {
                                if ( is_array($list) === false ) { $list = array(); }
                                $list[] = $rfu;
                            } else {
                                if ( is_array($errs) === false ) { $errs = array(); }
                                $errs[] = array( 'name'   => $FileName,
                                                 'size'   => $FileSize,
                                                 'type'   => strtolower($FileType),
                                                 'reason' => "Could Not Record File Data",
                                                );
                            }

                            // Copy the Data to S3
                            if ( USE_S3 == 1 ) {
                                $s3->putObject($s3->inputFile($fullPath, false), CDN_URL, $cdnPath, 'public-read');
                                unset($s3);
                            }

                        } else {
                            if ( is_array($errs) === false ) { $errs = array(); }
                            $errs[] = array( 'name'   => $FileName,
                                             'size'   => $FileSize,
                                             'type'   => strtolower($FileType),
                                             'reason' => "Bad File Name",
                                            );
                        }
                    }

                } else {
                    if ( is_array($errs) === false ) { $errs = array(); }
                    $errs[] = array( 'name'   => $FileName,
                                     'size'   => $FileSize,
                                     'type'   => strtolower($FileType),
                                     'reason' => (($ValidType) ? "Insufficient Storage Remaining" : "Unsupported File Type"),
                                    );
                }
            }

            // Reload the Bucket Data
            $this->_populateClass();

            // Return a Files Object Array
            return array( 'files'  => $list,
                          'bucket' => array( 'files' => $this->settings['bucket_files'],
                                             'limit' => $this->settings['bucket_size'],
                                             'used'  => $this->settings['bucket_used'],
                                            ),
                          'errors' => $errs,
                         );
        }

        // If We're Here, Nothing Was Found
        return "No Files Found";
    }

    /**
     *  Function Records the File and its Metadata to the Database and returns the appropriate Files object
     */
    private function _recordFileUpload( $FileName, $LocalName, $FileSize, $FileType, $FileHash, $geoData = false, $imgMeta = false, $hasProp, $isReduced = false, $isAnimated = false ) {
        if ( $Animated !== true ) { $Animated = false; }
        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[FILENAME]'   => sqlScrub($FileName),
                          '[FILELOCAL]'  => sqlScrub($LocalName),
                          '[FILEHASH]'   => sqlScrub($FileHash),
                          '[FILESIZE]'   => nullInt($FileSize),
                          '[FILEPATH]'   => sqlScrub('/' . intToAlpha($this->settings['_account_id']) . '/'),
                          '[FILETYPE]'   => sqlScrub($FileType),
                          '[IS_REDUCED]' => BoolYN($isReduced),
                          '[ANIMATED]'   => BoolYN($Animated),
                         );
        $sqlStr = readResource(SQL_DIR . '/files/recordFileUpload.sql', $ReplStr);
        $FileID = doSQLExecute($sqlStr);

        // Record the MetaData If It Exists
        if ( $FileID > 0 ) {
            $sqlStr = '';

            // Collect any Geo Meta We May Have
            if ( is_array($geoData) ) {
                foreach ( $geoData as $Key=>$Value ) {
                    if ( $Value !== false ) {
                        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                                          '[FILE_ID]'    => nullInt($FileID),
                                          '[KEY]'        => 'geo.' . sqlScrub($Key),
                                          '[VALUE]'      => sqlScrub($Value),
                                         );
                        if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                        $sqlStr .= readResource(SQL_DIR . '/files/setFileMeta.sql', $ReplStr);
                    }
                }
            }

            // Collect any Image Meta We May Have
            if ( is_array($imgMeta) ) {
                foreach ( $imgMeta as $Key=>$Value ) {
                    if ( $Value !== false ) {
                        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                                          '[FILE_ID]'    => nullInt($FileID),
                                          '[KEY]'        => sqlScrub($Key),
                                          '[VALUE]'      => sqlScrub($Value),
                                         );
                        if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                        $sqlStr .= readResource(SQL_DIR . '/files/setFileMeta.sql', $ReplStr);
                    }
                }
            }

            // Has the File Been Reduced in Size?
            $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                              '[FILE_ID]'    => nullInt($FileID),
                              '[KEY]'        => 'image.has_medium',
                              '[VALUE]'      => BoolYN($hasProp),
                             );
            if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
            $sqlStr .= readResource(SQL_DIR . '/files/setFileMeta.sql', $ReplStr);

            // Do We Have a Thumbnail-sized Image as well?
            $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                              '[FILE_ID]'    => nullInt($FileID),
                              '[KEY]'        => 'image.has_thumb',
                              '[VALUE]'      => BoolYN($isReduced),
                             );
            if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
            $sqlStr .= readResource(SQL_DIR . '/files/setFileMeta.sql', $ReplStr);

            // Write the Data to the Database If Applicable
            if ( $sqlStr != '' ) { $isOK = doSQLExecute($sqlStr); }

            // Set the file_id Value and Return a Files Object
            return $this->_getFileByID($FileID);
        }

        // If We're Here, The Write Failed
        return false;
    }

    /** ********************************************************************* *
     *  Private Functions
     ** ********************************************************************* */
    /**
     *  Function returns an Array of Files Objects
     */
    private function _getFilesList() {
        $PageID = nullInt($this->settings['page']) - 1;
        if ( $PageID < 0 ) { $PageID = 0; }

        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[FILE_ID]'    => nullInt($this->settings['file_id'], $this->settings['PgSub1']),
                          '[PAGE]'       => ($PageID * 50),
                         );

        $sqlStr = readResource(SQL_DIR . '/files/getFilesList.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $cdn_prefix = '//' . CDN_URL . '/' . intToAlpha($this->settings['_account_id']) . '/';
            $data = array();
            foreach ( $rslt as $Row ) {
                $is_deleted = YNBool($Row['is_deleted']);

                $data[] = array( 'file' => array( 'id'         => nullInt($Row['id']),
                                                  'name'       => (($is_deleted) ? false : NoNull($Row['name'])),
                                                  'size'       => (($is_deleted) ? false : nullInt($Row['size'])),
                                                  'mime'       => (($is_deleted) ? false : NoNull($Row['type'])),
                                                  'is_deleted' => $is_deleted,
                                                 ),

                                 'in_post'       => (($is_deleted) ? false : ((nullInt($Row['posts'])) ? true : false)),
                                 'in_meta'       => (($is_deleted) ? false : ((nullInt($Row['in_meta'])) ? true : false)),
                                 'is_avatar'     => (($is_deleted) ? false : ((nullInt($Row['is_avatar'])) ? true : false)),
                                 'has_metadata'  => (($is_deleted) ? false : YNBool($Row['has_meta'])),

                                 'cdn_url'       => (($is_deleted) ? false : $cdn_prefix . NoNull($Row['hash'])),

                                 'uploaded_at'   => (($is_deleted) ? false : NoNull($Row['uploaded_at'])),
                                 'uploaded_unix' => (($is_deleted) ? false : strtotime($Row['uploaded_at'])),
                                 'updated_at'    => NoNull($Row['updated_at']),
                                 'updated_unix'  => strtotime($Row['updated_at']),
                                );
            }

            // If We Have Data, Return It
            if ( count($data) > 0 ) { return $data; }
        }

        // If We're Here, There's Nothing
        return array();
    }

    /**
     *  Function Returns a File Object for a Given ID, or an Unhappy Boolean
     */
    private function _getFileByID( $FileID ) {
        $CleanID = nullInt($FileID, $this->settings['file_id']);
        if ( $FileID <= 0 ) { return false; }

        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[FILE_ID]'    => nullInt($CleanID),
                         );
        $sqlStr = readResource(SQL_DIR . '/files/getFileData.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $cdn_prefix = getCdnUrl();
            $data = false;
            $meta = false;

            foreach ( $rslt as $Row ) {
                $is_deleted = YNBool($Row['is_deleted']);

                if ( $data === false ) {
                    $data = array( 'id'         => nullInt($Row['file_id']),
                                   'name'       => (($is_deleted) ? false : NoNull($Row['public_name'])),
                                   'size'       => (($is_deleted) ? false : nullInt($Row['bytes'])),
                                   'type'       => (($is_deleted) ? false : NoNull($Row['type'])),
                                   'hash'       => (($is_deleted) ? false : NoNull($Row['hash'])),
                                   'guid'       => (($is_deleted) ? false : NoNull($Row['guid'])),

                                   'cdn_url'    => (($is_deleted) ? false : $cdn_prefix . NoNull($Row['cdn_path'])),
                                   'medium'     => false,
                                   'thumb'      => false,
                                   'meta'       => false,

                                   'created_at'   => (($is_deleted) ? false : date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at']))),
                                   'created_unix' => (($is_deleted) ? false : strtotime($Row['created_at'])),
                                   'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                                   'updated_unix' => strtotime($Row['updated_at']),
                                  );
                }

                // Add the Meta Value if Applicable
                if ( $is_deleted === false && NoNull($Row['key']) != '' && YNBool($Row['is_visible']) ) {
                    if ( is_array($meta) === false ) { $meta = array(); }

                    $Key = NoNull($Row['key']);
                    $Val = (is_numeric($Row['value'])) ? nullInt($Row['value']) : NoNull($Row['value']);
                    if ( is_string($Val) && in_array($Val, array('N', 'Y')) ) { $Val = YNBool($Val); }

                    if ( strpos($Key, '.') ) {
                        $kk = explode('.', $Key);
                        if ( array_key_exists($kk[0], $meta) === false ) { $meta[$kk[0]] = array(); }
                        $meta[$kk[0]][$kk[1]] = $Val;

                        // Do We Have Smaller Versions of the File?
                        if ( $kk[1] == 'has_medium' && $Val === true ) { $data['medium'] = $cdn_prefix . str_replace($Row['hash'], $Row['hash'] . '_medium', $Row['cdn_path']); }
                        if ( $kk[1] == 'has_thumb' && $Val === true ) { $data['thumb'] = $cdn_prefix . str_replace($Row['hash'], $Row['hash'] . '_thumb', $Row['cdn_path']); }

                    } else {
                        $meta[$Key] = $Val;
                    }
                }
            }

            // Add the Meta to the Object if it's Applicable
            if ( is_array($meta) ) { $data['meta'] = $meta; }

            // Return the File Object
            return $data;
        }

        // If We're Here, There's No File
        return false;
    }

    /**
     *  Function Marks a File as Deleted After Scrubbing it from S3
     */
    private function _deleteFile() {
        $FileID = nullInt($this->settings['file_id'], $this->settings['PgSub1']);
        if ( $FileID <= 0 ) { return false; }
        $isGood = false;
        $rVal = "Could Not Delete File.";

        // Confirm Ownership of the File
        $data = $this->_getFilesList();
        if ( is_array($data) === false ) { return "You Do Not Own This File."; }

        // Remove the File from S3 If It Exists
        if ( USE_S3 == 1 ) {
            $cdnPath = str_replace('//' . CDN_URL . '/', '', $data[0]['cdn_url']);
            if ( $cdnPath != '' ) {
                require_once(LIB_DIR . '/s3.php');

                $s3 = new S3(AWS_ACCESS_KEY, AWS_SECRET_KEY);
                $isGood = $s3->deleteObject(CDN_URL, $cdnPath);
                unset($s3);
            }
        }

        // If the File Deletion Was Successful, Update the Database
        if ( $isGood ) {
            $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                              '[FILE_ID]'    => $FileID,
                             );
            $sqlStr = readResource(SQL_DIR . '/files/deleteFileByID.sql', $ReplStr);
            $rslt = doSQLExecute($sqlStr);

            // Collect the File Data One Last Time
            $rVal = $this->_getFilesList();

        } else {
            return "Could Not Remove File from CDN. Please Contact Support.";
        }

        // Return a Happy Array of Data or an Unhappy Message
        return $rVal;
    }


    /** ********************************************************************* *
     *  Internal Functions
     ** ********************************************************************* */
    /**
     *  Function Returns the File Extension of a Given File
     */
    private function _getFileExtension( $FileName, $FileType ) {
        $ext = NoNull(substr(strrchr($FileName,'.'), 1));
        if ( $ext == '' ) {
            switch ( strtolower($FileType) ) {
                case 'audio/x-mp3':
                case 'audio/mp3':
                    $ext = 'mp3';
                    break;

                case 'audio/x-mp4':
                case 'audio/mp4':
                case 'video/mp4':
                    $ext = 'mp4';
                    break;

                case 'audio/x-m4a':
                case 'audio/m4a':
                case 'video/m4a':
                    $ext = 'm4a';
                    break;

                case 'video/m4v':
                    $ext = 'm4v';
                    break;

                case 'audio/mpeg':
                    $ext = 'mpeg';
                    break;

                case 'image/jpeg':
                case 'image/jpg':
                    $ext = 'jpg';
                    break;

                case 'image/x-windows-bmp':
                case 'image/bmp':
                    $ext = 'bmp';
                    break;

                case 'image/gif':
                    $ext = 'gif';
                    break;

                case 'image/png':
                    $ext = 'png';
                    break;

                case 'image/tiff':
                    $ext = 'tiff';
                    break;

                case 'video/quicktime':
                    $ext = 'mov';
                    break;

                case 'application/x-mpegurl':
                    $ext = 'm3u8';
                    break;

                case 'video/mp2t':
                    $ext = 'ts';
                    break;
            }
        }

        // Return the File Extension
        return $ext;
    }

    /**
     *  Function Determines if the DataType Being Uploaded is Valid or Not
     */
    private function _isValidUploadType( $FileType, $Extension ) {
        $valids = array( 'audio/mp3', 'audio/mp4', 'audio/m4a', 'audio/x-mp3', 'audio/x-mp4', 'audio/mpeg', 'audio/x-m4a',
                         'image/gif', 'image/x-gif', 'image/jpg', 'image/jpeg', 'image/png', 'image/tiff', 'image/bmp', 'image/x-windows-bmp',
                         'video/quicktime', 'video/m4a', 'video/m4v', 'video/mp4', 'application/x-mpegurl', 'video/mp2t'
                        );

        // Is the FileType in the Array?
        if ( in_array(strtolower($FileType), $valids) ) {
            return true;
        } else {
            if ( NoNull($FileType) == '' && NoNull($Extension) != '' ) {
                if ( in_array(strtolower(NoNull($Extension)), array('jpg', 'jpeg', 'gif', 'png')) ) { return true; }
            }
            writeNote( "Invalid FileType: $FileType", true );
        }

        // If We're Here, No Dice
        return false;
    }

    private function _isResizableImage( $FileType ) {
        $valids = array( 'image/jpg', 'image/jpeg', 'image/gif', 'image/x-gif', 'image/png', 'image/tiff', 'image/bmp', 'image/x-windows-bmp' );

        // Return the Boolean Response
        return in_array(strtolower($FileType), $valids);
    }

    /**
     *  Function Returns a Boolean Stating Whether a Gif is Animated or Not
     */
    private function _isGifImage( $FileType ) {
        $valids = array( 'image/gif', 'image/x-gif' );

        // Return the Boolean Response
        return in_array(strtolower($FileType), $valids);
    }

    /** ********************************************************************* *
     *  Class Functions
     ** ********************************************************************* */
    /**
     *  Function Sets a Message in the Meta Field
     */
    private function _setMetaMessage( $msg, $code = 0 ) {
        if ( is_array($this->settings['errors']) === false ) { $this->settings['errors'] = array(); }
        if ( NoNull($msg) != '' ) { $this->settings['errors'][] = NoNull($msg); }
        if ( $code > 0 && nullInt($this->settings['status']) == 0 ) { $this->settings['status'] = nullInt($code); }
    }
}
?>