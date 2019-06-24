<?php

/**
 * @author Jason F. Irwin
 *
 * Class contains the rules and methods called for Accounts
 */
require_once( LIB_DIR . '/functions.php');

class Account {
    var $settings;
    var $strings;
    var $cache;

    function __construct( $settings, $strings = false ) {
        $this->settings = $settings;
        $this->strings = ((is_array($strings)) ? $strings : getLangDefaults($this->settings['_language_code']));
        $this->cache = array();
    }

    /** ********************************************************************* *
     *  Perform Action Blocks
     ** ********************************************************************* */
    public function performAction() {
        $ReqType = NoNull(strtolower($this->settings['ReqType']));
        $rVal = false;

        // Perform the Action
        switch ( $ReqType ) {
            case 'get':
                $rVal = $this->_performGetAction();
                break;

            case 'post':
            case 'put':
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
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        $rVal = false;

        switch ( $Activity ) {
            case 'checkname':
            case 'chkname':
                return $this->_checkAvailability();
                break;

            case 'list':
                if ( !$this->settings['_logged_in']) { return "You Need to Log In First"; }
                return $this->_getAccountList();
                break;

            case 'preferences':
            case 'preference':
            case 'prefs':
                return $this->_getPreference();
                break;

            case 'profile':
            case 'bio':
                return $this->_getPublicProfile();
                break;

            case 'posts':
                return $this->_getProfilePosts();
                break;

            case 'me':
                if ( !$this->settings['_logged_in']) { return "You Need to Log In First"; }
                return $this->_getProfile();
                break;

            case 'summary':
                if ( !$this->settings['_logged_in']) { return "You Need to Log In First"; }
                return $this->_getAccountSummary();
                break;

            default:
                // Do Nothing
        }

        // If We're Here, There Was No Matching Activity
        return false;
    }

    private function _performPostAction() {
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        $excludes = array( 'create' );
        $rVal = false;

        // Check the User Token is Valid
        if ( !$this->settings['_logged_in'] && in_array($Activity, $excludes) === false ) { return "You Need to Log In First"; }

        switch ( $Activity ) {
            case 'bio':
                $rVal = $this->_setPublicProfile();
                break;

            case 'create':
                $rVal = $this->_createAccount();
                break;

            case 'preference':
                return $this->_setPreference();
                break;

            case 'profile':
            case 'me':
                $rVal = $this->_setProfile();
                break;

            case 'relations':
            case 'relation':
                $rVal = $this->_setRelation();
                break;

            case 'follow':
                $this->settings['follows'] = 'Y';
                $rVal = $this->_setRelation();
                break;

            case 'mute':
                $this->settings['muted'] = 'Y';
                $rVal = $this->_setRelation();
                break;

            case 'block':
                $this->settings['blocked'] = 'Y';
                $rVal = $this->_setRelation();
                break;

            case 'star':
                $this->settings['starred'] = 'Y';
                $rVal = $this->_setRelation();
                break;

            default:
                // Do Nothing
        }

        // Return the Array of Data or an Unhappy Boolean
        return $rVal;
    }

    private function _performDeleteAction() {
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        $rVal = false;

        // Check the User Token is Valid
        if ( !$this->settings['_logged_in']) { return "You Need to Log In First"; }

        switch ( $Activity ) {
            case 'relations':
            case 'relation':
                $rVal = $this->_setRelation();
                break;

            case 'follow':
                $this->settings['follows'] = 'N';
                $rVal = $this->_setRelation();
                break;

            case 'mute':
                $this->settings['muted'] = 'N';
                $rVal = $this->_setRelation();
                break;

            case 'block':
                $this->settings['blocked'] = 'N';
                $rVal = $this->_setRelation();
                break;

            case 'star':
                $this->settings['starred'] = 'N';
                $rVal = $this->_setRelation();
                break;

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
    public function getAccountInfo($AcctID) { return $this->_getAccountInfo($AcctID); }
    public function getPublicProfile() { return $this->_getPublicProfile(); }
    public function getPreference($Type) {
        $data = $this->_getPreference($Type);
        return $data['value'];
    }

    /** ********************************************************************* *
     *  Private Functions
     ** ********************************************************************* */
    /**
     *  Function Collects the Account Information if Required and Returns the Requested Info
     */
    private function _getAccountInfo( $AccountID ) {
        $CleanID = nullInt($AccountID);
        if ( $CleanID <= 0 ) { return false; }

        if ( !array_key_exists($CleanID, $this->cache) ) { $this->_readAccountInfo($AccountID); }
        return ( is_array($this->cache[$CleanID]) ) ? $this->cache[$CleanID] : false;
    }

    /**
     *  Function Populates the Cache Variable with Account Data for a Given Set of IDs
     */
    private function _readAccountInfo( $AccountIDs ) {
        $Accounts = explode(',', $AccountIDs);
        if ( is_array($Accounts) ) {
            $AcctList = array();

            foreach ( $Accounts as $id ) {
                $chkID = nullInt($id);
                if ( $chkID > 0 && !array_key_exists($chkID, $this->cache) ) { $AcctList[] = nullInt($id); }
            }

            // Get a List of Person Records
            if ( count($AcctList) > 0 ) {
                $ReplStr = array( '[ACCOUNT_IDS]' => implode(',', $AcctList) );
                $sqlStr = readResource(SQL_DIR . '/account/getAccountPersonInfo.sql', $ReplStr);
                $rslt = doSQLQuery($sqlStr);
                if ( is_array($rslt) ) {
                    foreach ( $rslt as $Row ) {
                        $AcctID = nullInt($Row['account_id']);
                        $lang_label = 'lbl_' . NoNull($Row['language_code']);
                        $this->cache[$AcctID] = array( 'display_name'   => NoNull($Row['display_name']),
                                                       'avatar_url'     => NoNull($Row['avatar_url'], 'default.png'),
                                                       'type'           => NoNull($Row['type']),
                                                       'guid'           => NoNull($Row['guid']),
                                                       'is_you'         => YNBool(BoolYN($Row['account_id'] == $this->settings['_account_id'])),

                                                       'created_at'     => date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at'])),
                                                       'created_unix'   => strtotime($Row['created_at']),
                                                       'updated_at'     => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                                                       'updated_unix'   => strtotime($Row['updated_at']),
                                                      );
                    }
                }
            }
        }
    }

    /** ********************************************************************* *
     *  Account Creation
     ** ********************************************************************* */
    private function _createAccount() {
        if ( !defined('DEFAULT_LANG') ) { define('DEFAULT_LANG', 'en-us'); }
        if ( !defined('DEFAULT_DOMAIN') ) { define('DEFAULT_DOMAIN', ''); }
        if ( !defined('SHA_SALT') ) { define('SHA_SALT', ''); }
        if ( !defined('TIMEZONE') ) { define('TIMEZONE', ''); }

        $CleanPass = NoNull($this->settings['pass'], $this->settings['password']);
        $CleanName = NoNull($this->settings['name'], $this->settings['persona']);
        $CleanMail = NoNull($this->settings['mail'], $this->settings['email']);
        $CleanTOS = NoNull($this->settings['terms'], $this->settings['tos']);
        $CleanDomain = NoNull($this->settings['domain'], DEFAULT_DOMAIN);
        $CleanLang = NoNull($this->settings['lang'], DEFAULT_LANG);
        $Redirect = NoNull($this->settings['redirect'], $this->settings['is_web']);

        if ( mb_strlen($CleanPass) <= 6 ) {
            $this->_setMetaMessage( "Password is too weak. Please choose a better one.", 400 );
            return false;
        }

        if ( mb_strlen($CleanName) < 2 ) {
            $this->_setMetaMessage( "Nickname is too short. Please choose a longer one.", 400 );
            return false;
        }

        if ( mb_strlen($CleanMail) <= 5 ) {
            $this->_setMetaMessage( "Email address is too short. Please enter a correct address.", 400 );
            return false;
        }

        if ( validateEmail($CleanMail) === false ) {
            $this->_setMetaMessage( "Email address does not appear correct. Please enter a correct address.", 400 );
            return false;
        }

        if ( YNBool($CleanTOS) === false ) {
            $this->_setMetaMessage( "Please read and accept the Terms of Service before creating an account.", 400 );
            return false;
        }

        // Ensure the Start of the Domain has a period
        if ( mb_substr($CleanDomain, 0, 1) != '.' ) {
            $CleanDomain = '.' . $CleanDomain;
        }

        // If we're here, we *might* be good. Create the account.
        $ReplStr = array( '[DOMAIN]' => sqlScrub($CleanDomain),
                          '[NAME]'   => sqlScrub($CleanName),
                          '[MAIL]'   => sqlScrub($CleanMail),
                          '[PASS]'   => sqlScrub($CleanPass),
                          '[LANG]'   => sqlScrub($CleanLang),
                          '[SALT]'   => sqlScrub(SHA_SALT),
                          '[ZONE]'   => sqlScrub(TIMEZONE),
                         );
        $sqlStr = prepSQLQuery( "CALL CreateAccount('[NAME]', '[PASS]', '[MAIL]', '[SALT]', '[DOMAIN]' );", $ReplStr );
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $ChannelId = false;
            $SiteUrl = false;
            $SiteId = false;
            $PaGuid = false;
            $AcctID = false;
            $Token = false;

            foreach ( $rslt as $Row ) {
                $SiteUrl = NoNull($Row['site_url']);
                $PaGuid = NoNull($Row['persona_guid']);
                $AcctID = nullInt($Row['account_id']);
            }

            // Create the Channel and Site
            if ( NoNull($SiteUrl) != '' && NoNull($PaGuid) != '' && nullInt($AcctID) > 0 ) {
                $ReplStr['[PERSONA_GUID]'] = sqlScrub($PaGuid);
                $ReplStr['[ACCOUNT_ID]'] = nullInt($AcctID);
                $ReplStr['[SITE_URL]'] = sqlScrub($SiteUrl);
                $ReplStr['[SITE_NAME]'] = sqlScrub( "$CleanName's Space");
                $ReplStr['[SITE_DESCR]'] = sqlScrub( "All About $CleanName");

                $sqlStr = prepSQLQuery( "CALL CreateSite([ACCOUNT_ID], '[PERSONA_GUID]', '[SITE_NAME]', '[SITE_DESCR]', '', '[SITE_URL]', 'visibility.public');", $ReplStr );
                $tslt = doSQLQuery($sqlStr);
                if ( is_array($tslt) ) {
                    foreach ( $tslt as $Row ) {
                        $ChannelId = nullInt($Row['channel_id']);
                        $SiteId = nullInt($Row['site_id']);
                    }
                }
            }

            // If CloudFlare is being used, configure the CNAME Record Accordingly
            if ( !defined('CLOUDFLARE_API_KEY') ) { define('CLOUDFLARE_API_KEY', ''); }
            $zone = false;

            if ( NoNull(CLOUDFLARE_API_KEY) != '' ) {
                require_once(LIB_DIR . '/system.php');
                $sys = new System( $this->settings );
                $zone = $sys->createCloudFlareZone( $SiteUrl );
                unset($sys);
            }

            // Collect an Authentication Token and (if needs be) Redirect
            $sqlStr = prepSQLQuery( "CALL PerformDirectLogin([ACCOUNT_ID]);", $ReplStr );
            $tslt = doSQLQuery($sqlStr);
            if ( is_array($tslt) ) {
                foreach ( $tslt as $Row ) {
                    $Token = TOKEN_PREFIX . intToAlpha($Row['token_id']) . '_' . NoNull($Row['token_guid']);
                }
            }
        }

        // What sort of return are we looking for?
        $url = NoNull($this->settings['HomeURL']) . '/welcome';
        switch ( strtolower($Redirect) ) {
            case 'web_redirect':
                if ( is_string($Token) ) {
                    $url .= '?token=' . $Token;
                } else {
                    $url = NoNull($this->settings['HomeURL']) . '/nodice';
                }
                redirectTo( $url );
                break;

            default:
                if ( is_string($Token) ) {
                    return array( 'token' => $Token,
                                  'url'   => NoNull($url),
                                 );
                } else {
                    $this->_setMetaMessage( "Could not create Account", 400 );
                    return false;
                }
        }

        // If We're Here, Something is Really Off
        return false;
    }

    private function _checkAvailability() {
        if ( !defined('DEFAULT_DOMAIN') ) { define('DEFAULT_DOMAIN', ''); }
        $CleanDomain = NoNull($this->settings['domain'], DEFAULT_DOMAIN);
        $CleanName = NoNull($this->settings['name'], $this->settings['persona']);

        if ( mb_strlen($CleanName) < 2 ) {
            $this->_setMetaMessage( "This Name is Too Short", 400 );
            return false;
        }

        if ( mb_strlen($CleanName) > 40 ) {
            $this->_setMetaMessage( "This Name is Too Long", 400 );
            return false;
        }

        if ( mb_strlen($CleanDomain) <= 3 || mb_strlen($CleanDomain) > 100 ) {
            $this->_setMetaMessage( "The Domain Name Appears Invalid", 400 );
            return false;
        }

        // Ensure the Start of the Domain has a period
        if ( mb_substr($CleanDomain, 0, 1) != '.' ) {
            $CleanDomain = '.' . $CleanDomain;
        }

        // Prepare the SQL Query
        $ReplStr = array( '[NAME]'   => sqlScrub($CleanName),
                          '[DOMAIN]' => sqlScrub($CleanDomain),
                         );
        $sqlStr = prepSQLQuery( "CALL CheckPersonaAvailable('[NAME]', '[DOMAIN]');", $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            foreach ( $rslt as $Row ) {
                return array( 'name' => NoNull($Row['persona_name']),
                              'url'  => NoNull($Row['domain_url']),
                             );
            }
        }

        // If We're Here, Either Something's Wrong or the Requested Name in use
        return array();
    }

    /** ********************************************************************* *
     *  Persona Relations Management
     ** ********************************************************************* */
    /**
     *  Function returns a list of every Persona an Account has a Relation record with, including their own.
     */
    private function _getRelationsList() {
        $CleanGUID = NoNull($this->settings['PgSub1']);
        if ( strlen($CleanGUID) != 36 ) {
            $CleanGUID = NoNull($this->settings['persona_guid'], $this->settings['persona-guid']);
        }

        // Ensure the GUIDs are valid
        if ( strlen($CleanGUID) != 36 ) {
            $this->_setMetaMessage( "Invalid Persona GUID Supplied", 400 );
            return false;
        }

        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[PERSONA_GUID]' => sqlScrub($CleanGUID),
                         );
        $sqlStr = prepSQLQuery("CALL GetRelationsList([ACCOUNT_ID], '[PERSONA_GUID]');", $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $data = array();
            foreach ( $rslt as $Row ) {
                $site = false;
                if ( NoNull($Row['site_url']) != '' ) {
                    $site = array( 'name' => NoNull($Row['site_name']),
                                   'url'  => NoNull($Row['site_url']),
                                  );
                }

                $data[] = array( 'guid'        => NoNull($post['persona_guid']),
                                 'as'          => '@' . NoNull($post['name']),
                                 'name'        => NoNull($post['display_name']),
                                 'avatar'      => NoNull($post['avatar_url']),
                                 'site'        => $site,

                                 'pin'         => NoNull($post['pin_type'], 'pin.none'),
                                 'you_follow'  => YNBool($post['follows']),
                                 'is_muted'    => YNBool($post['is_muted']),
                                 'is_starred'  => YNBool($post['is_starred']),
                                 'is_blocked'  => YNBool($post['is_blocked']),
                                 'is_you'      => YNBool($post['is_you']),

                                 'profile_url' => NoNull($post['profile_url']),
                                );
            }

            /* If we have data, return it */
            if ( count($data) > 0 ) { return $data; }
        }

        // If We're Here, There's Nothing ... which should never happen
        return array();
    }

    private function _setRelation() {
        $CleanGUID = NoNull($this->settings['persona_guid'], $this->settings['persona-guid']);
        $RelatedGUID = NoNull($this->settings['PgSub1']);
        if ( strlen($RelatedGUID) != 36 ) {
            $RelatedGUID = NoNull($this->settings['related_guid'], $this->settings['related-guid']);
        }

        // Ensure the GUIDs are valid
        if ( strlen($CleanGUID) != 36 ) {
            $this->_setMetaMessage( "Invalid Persona GUID Supplied", 400 );
            return false;
        }
        if ( strlen($RelatedGUID) != 36 ) {
            $this->_setMetaMessage( "Invalid Related GUID Supplied", 400 );
            return false;
        }

        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[PERSONA_GUID]' => sqlScrub($CleanGUID),
                          '[RELATED_GUID]' => sqlScrub($RelatedGUID),

                          '[FOLLOWS]'      => sqlScrub($this->settings['follows']),
                          '[MUTED]'        => sqlScrub($this->settings['muted']),
                          '[BLOCKED]'      => sqlScrub($this->settings['blocked']),
                          '[STARRED]'      => sqlScrub($this->settings['starred']),
                          '[PINNED]'       => sqlScrub($this->settings['pin']),
                         );
        $sqlStr = prepSQLQuery("CALL SetPersonaRelation([ACCOUNT_ID], '[PERSONA_GUID]', '[RELATED_GUID]', '[FOLLOWS]', '[MUTED]', '[BLOCKED]', '[STARRED]', '[PINNED]');", $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            foreach ( $rslt as $Row ) {
                return array( 'guid'         => NoNull($Row['related_guid']),

                              'follows'      => YNBool($Row['follows']),
                              'is_muted'     => YNBool($Row['is_muted']),
                              'is_blocked'   => YNBool($Row['is_blocked']),
                              'is_starred'   => YNBool($Row['is_starred']),
                              'pin_type'     => NoNull($Row['pin_type'], 'pin.none'),

                              'created_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at'])),
                              'created_unix' => strtotime($Row['created_at']),
                              'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                              'updated_unix' => strtotime($Row['updated_at']),
                             );
            }
        }

        // If We're Here, We Couldn't Do Anything
        return false;
    }

    /** ********************************************************************* *
     *  Profile Management
     ** ********************************************************************* */
    private function _setProfileLanguage() {
        $CleanLang = NoNull($this->settings['language_code'], $this->settings['language']);
        if ( $CleanLang == '' ) { return "Invalid Language Preference Supplied"; }

        // Update the Database
        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[LANG_CODE]'  => sqlScrub($CleanLang),
                         );
        $sqlStr = readResource(SQL_DIR . '/account/setLanguage.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);

        // Return a Profile Object for the Current Account or an Unhappy String
        return $this->_getProfile();
    }

    /**
     *  Function Updates a Person's Profile Data
     */
    private function _setProfile() {
        $CleanName = NoNull(NoNull($this->settings['pref_name'], $this->settings['pref-name']), $this->settings['display_as']);
        $CleanLang = NoNull(NoNull($this->settings['pref_lang'], $this->settings['pref-lang']), $this->settings['language']);
        $CleanMail = NoNull(NoNull($this->settings['pref_mail'], $this->settings['pref-mail']), $this->settings['mail_addr']);
        $CleanTime = NoNull(NoNull($this->settings['pref_zone'], $this->settings['pref-zone']), $this->settings['timezone']);
        $CleanGUID = NoNull($this->settings['persona_guid'], $this->settings['persona-guid']);

        // Perform Some Basic Validation
        if ( mb_strlen($CleanGUID) != 36 ) {
            $this->_setMetaMessage("Invalid Persona GUID Supplied", 400);
            return false;
        }
        if ( $CleanMail != '' ) {
            if ( validateEmail($CleanMail) === false ) {
                $this->_setMetaMessage("Invalid Email Address Supplied", 400);
                return false;
            }
        }

        // Check for Values and Set Existing Values if Needs Be
        if ( NoNull($CleanLang) == '' ) { $CleanLang = NoNull($this->settings['_language_code']); }
        if ( NoNull($CleanTime) == '' ) { $CleanTime = NoNull($this->settings['_timezone']); }

        // Update the Database
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[PERSONA_GUID]' => sqlScrub($CleanGUID),

                          '[PREF_NAME]'    => sqlScrub($CleanName),
                          '[PREF_LANG]'    => sqlScrub($CleanLang),
                          '[PREF_MAIL]'    => sqlScrub($CleanMail),
                          '[PREF_TIME]'    => sqlScrub($CleanTime),
                         );
        $sqlStr = readResource(SQL_DIR . '/account/setProfile.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);

        // Return a Profile Object for the Current Account or an Unhappy String
        return $this->_getProfile();
    }

    private function _getProfile() {
        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']) );
        $sqlStr = readResource(SQL_DIR . '/account/getProfile.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            foreach ( $rslt as $Row ) {
                $strings = getLangDefaults($Row['language_code']);

                $rVal = array( 'guid'           => NoNull($Row['guid']),
                               'type'           => NoNull($Row['type']),
                               'timezone'       => Nonull($Row['timezone']),

                               'display_name'   => NoNull($Row['display_name']),
                               'mail_address'   => NoNull($Row['mail_address']),

                               'language'       => array( 'code' => NoNull($Row['language_code']),
                                                          'name' => NoNull($strings['lang_name'], $Row['language_name']),
                                                         ),
                               'personas'       => $this->_getAccountPersonas($Row['account_id']),
                               'bucket'         => array( 'storage'   => 0,
                                                          'available' => 0,
                                                          'files'     => 0,
                                                         ),

                               'created_at'     => date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at'])),
                               'created_unix'   => strtotime($Row['created_at']),
                               'updated_at'     => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                               'updated_unix'   => strtotime($Row['updated_at']),
                              );
            }
        }

        // Return the Profile Object or an Unhappy String
        return $rVal;
    }

    /**
     *  Function Records an Updated Public Profile for a Given Persona.guid Value
     */
    private function _setPublicProfile() {
        $CleanGUID = '';
        $opts = ['PgRoot', 'PgSub1', 'persona_guid', 'guid'];
        foreach ( $opts as $opt ) {
            $guid = NoNull($this->settings[ $opt ]);
            if ( $CleanGUID == '' && strlen($guid) == 36 ) { $CleanGUID = $guid; }
        }
        $CleanBio = NoNull($this->settings['bio_text'], $this->settings['persona_bio']);

        // Ensure We Have a GUID
        if ( strlen($CleanGUID) != 36 ) { return "Invalid Persona GUID Supplied"; }

        // Collect the Data
        $ReplStr = array( '[PERSONA_GUID]' => sqlScrub($CleanGUID),
                          '[PERSONA_BIO]'  => sqlScrub($CleanBio),
                          '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                         );
        $sqlStr = readResource(SQL_DIR . '/account/setPublicProfile.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);
        if ( $rslt > 0 ) {
            return $this->_getPublicProfile();
        }

        // If We're Here, We Couldn't Update the Public Profile
        return "Could Not Update Public Profile";
    }

    /**
     *  Function Builds the Public Profile for a Given Persona.guid Value
     */
    private function _getPublicProfile() {
        $CleanGUID = '';
        $opts = ['PgRoot', 'PgSub1', 'persona_guid', 'guid'];
        foreach ( $opts as $opt ) {
            $guid = NoNull($this->settings[ $opt ]);
            if ( $CleanGUID == '' && strlen($guid) == 36 ) { $CleanGUID = $guid; }
        }

        // Ensure We Have a GUID
        if ( strlen($CleanGUID) != 36 ) { return "Invalid Persona GUID Supplied"; }

        // Collect the Data
        $ReplStr = array( '[PERSONA_GUID]' => sqlScrub($CleanGUID),
                          '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                         );
        $sqlStr = prepSQLQuery("CALL GetPublicProfile([ACCOUNT_ID], '[PERSONA_GUID]');", $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            foreach ( $rslt as $Row ) {
                $bio_html = NoNull($Row['persona_bio']);
                if ( $bio_html != '' ) {
                    require_once(LIB_DIR . '/posts.php');
                    $post = new Posts($this->settings);
                    $bio_html = $post->getMarkdownHTML($bio_html, 0, false, true);
                    unset($post);
                }

                return array( 'guid'         => NoNull($Row['persona_guid']),
                              'timezone'     => Nonull($Row['timezone']),
                              'as'           => NoNull($Row['name']),
                              'name'         => NoNull(NoNull($Row['first_name']) . ' ' . NoNull($Row['last_name']), $Row['display_name']),
                              'avatar_url'   => NoNull($Row['site_url'] . '/avatars/' . $Row['avatar_img']),
                              'site_url'     => NoNull($Row['site_url']),
                              'bio'          => array( 'text' => NoNull($Row['persona_bio']),
                                                       'html' => $bio_html,
                                                      ),

                              'pin'          => NoNull($Row['pin_type'], 'pin.none'),
                              'you_follow'   => YNBool($Row['follows']),
                              'is_muted'     => YNBool($Row['is_muted']),
                              'is_starred'   => YNBool($Row['is_starred']),
                              'is_blocked'   => YNBool($Row['is_blocked']),
                              'is_you'       => YNBool($Row['is_you']),
                              'days'         => nullInt($Row['days']),

                              'created_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at'])),
                              'created_unix' => strtotime($Row['created_at']),
                              );
            }
        }

        // If We're Here, There Is No Persona
        return false;
    }

    /**
     *  Function Collects the Most Recent posts associated with a Persona
     */
    private function _getProfilePosts() {
        $CleanGUID = NoNull($this->settings['guid'], $this->settings['PgSub1']);
        if ( $CleanGUID == 'me' && NoNull($this->settings['_persona_guid']) != '' ) {
            $CleanGUID = $this->settings['_persona_guid'];
        }

        $this->settings['_for_guid'] = NoNull($CleanGUID);

        require_once(LIB_DIR . '/posts.php');
        $posts = new Posts($this->settings);
        $data = $posts->getPersonaPosts();
        unset($posts);

        // If we have data, return it
        if ( is_array($data) && count($data) > 0 ) { return $data; }

        // If We're Here, There's Nothing
        return array();
    }

    private function _getAccountPersonas( $AccountID = 0 ) {
        if ( nullInt($AccountID) <= 0 ) { return false; }

        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']) );
        $sqlStr = readResource(SQL_DIR . '/account/getPersonas.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $cdnUrl = getCdnUrl();
            $data = false;

            foreach ( $rslt as $Row ) {
                $data = array( 'guid'           => NoNull($Row['guid']),

                               'display_name'   => NoNull($Row['display_name']),
                               'first_name'     => NoNull($Row['first_name']),
                               'last_name'      => NoNull($Row['last_name']),
                               'email'          => NoNull($Row['email']),

                               'avatar_url'     => "$cdnUrl/" . NoNull($Row['avatar_img']),
                               'is_active'      => YNBool($Row['is_active']),

                               'created_at'     => date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at'])),
                               'created_unix'   => strtotime($Row['created_at']),
                               'updated_at'     => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                               'updated_unix'   => strtotime($Row['updated_at']),
                              );
            }

            // If We Have Data, Return It
            if ( is_array($data) ) { return $data; }
        }

        // If We're Here, There Are No Personas
        return false;
    }

    /** ********************************************************************* *
     *  Preferences
     ** ********************************************************************* */
    /**
     *  Function Sets a Person's Preference and Returns a Preference Object
     */
    private function _setPreference() {
        $CleanValue = NoNull($this->settings['value']);
        $CleanType = NoNull($this->settings['type'], $this->settings['key']);

        if ( $CleanValue == '' ) {
            $this->_setMetaMessage("Invalid Value Passed", 400);
            return false;
        }
        if ( $CleanType == '' ) {
            $this->_setMetaMessage("Invalid Type Key Passed", 400);
            return false;
        }

        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[VALUE]'      => sqlScrub($CleanValue),
                          '[TYPE_KEY]'   => strtolower(sqlScrub($CleanType)),
                         );
        $sqlStr = readResource(SQL_DIR . '/account/setPreference.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);
        if ( $rslt ) { return $this->_getPreference(); }

        // Return the Preference Object or an Unhappy String
        $this->_setMetaMessage("Could Not Record Account Preference", 400);
        return false;
    }

    private function _getPreference( $type = '' ) {
        $CleanType = NoNull($type, NoNull($this->settings['type'], $this->settings['key']));
        if ( $CleanType == '' ) {
            $this->_setMetaMessage("Invalid Type Key Passed", 400);
            return false;
        }

        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[TYPE_KEY]'   => strtolower(sqlScrub($CleanType)),
                         );
        $sqlStr = readResource(SQL_DIR . '/account/getPreference.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $data = array();
            foreach ( $rslt as $Row ) {
                $data[] = array( 'type'         => NoNull($Row['type']),
                                 'value'        => NoNull($Row['value']),

                                 'created_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['created_at'])),
                                 'created_unix' => strtotime($Row['created_at']),
                                 'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                                 'updated_unix' => strtotime($Row['updated_at']),
                                );
            }

            // If We Have Data, Return it
            if ( count($data) > 0 ) { return (count($data) == 1) ? $data[0] : $data; }
        }

        // Return the Preference Object or an empty array
        return array();
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