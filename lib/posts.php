<?php

/**
 * @author Jason F. Irwin
 *
 * Class contains the rules and methods called to manage Posts
 */
require_once(LIB_DIR . '/functions.php');
require_once(LIB_DIR . '/markdown.php');
use \Michelf\Markdown;

class Posts {
    var $settings;
    var $strings;
    var $geo;

    function __construct( $settings, $strings = false ) {
        $this->settings = $settings;
        $this->strings = ((is_array($strings)) ? $strings : getLangDefaults($this->settings['_language_code']));
        $this->geo = false;
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
        if ( mb_strlen($Activity) == 36 ) { $Activity = 'read'; }
        $rVal = false;

        switch ( $Activity ) {
            case 'globals':
            case 'global':
                $rVal = $this->_getTimeline('global');
                break;

            case 'mentions':
            case 'mention':
                $rVal = $this->_getTimeline('mentions');
                break;

            case 'list':
            case '':
                $rVal = false;
                break;

            case 'read':
                $rVal = $this->_getPostByGUID();
                break;

            case 'thread':
                $rVal = $this->_getThreadByGUID();
                break;

            default:

        }

        // Return the Array of Data or an Unhappy Boolean
        return $rVal;
    }

    private function _performPostAction() {
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        if ( mb_strlen($Activity) == 36 ) { $Activity = 'edit'; }
        $rVal = false;

        // Check the User Token is Valid
        if ( !$this->settings['_logged_in']) { return "You Need to Log In First"; }

        switch ( $Activity ) {
            case 'write':
            case 'edit':
            case '':
                $rVal = $this->_writePost();
                break;

            case 'star':
                $rVal = $this->_setPostStar();
                break;

            default:
                // Do Nothing
        }

        // Return the Array of Data or an Unhappy Boolean
        return $rVal;
    }

    private function _performDeleteAction() {
        $Activity = strtolower(NoNull($this->settings['PgSub2'], $this->settings['PgSub1']));
        if ( mb_strlen($Activity) == 36 ) { $Activity = 'delete'; }
        $rVal = false;

        // Check the User Token is Valid
        if ( !$this->settings['_logged_in']) { return "You Need to Log In First"; }

        switch ( $Activity ) {
            case 'delete':
                $rVal = $this->_deletePost();
                break;

            case 'star':
                $rVal = $this->_setPostStar();
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

    /**
     *  Function Returns Whether the Dataset May Have More Information or Not
     */
    public function getHasMore() {
        return BoolYN($this->settings['has_more']);
    }

    /** ********************************************************************* *
     *  Public Functions
     ** ********************************************************************* */
    public function getPageHTML( $data ) { return $this->_getPageHTML($data); }
    public function getMarkdownHTML( $text, $post_id, $isNote, $showLinkURL ) { return $this->_getMarkdownHTML( $text, $post_id, $isNote, $showLinkURL); }
    public function getPopularPosts() { return $this->_getPopularPosts(); }

    /** ********************************************************************* *
     *  Private Functions
     ** ********************************************************************* */
    /**
     *  Function returns a Post based on the Post.GUID Supplied or an Unhappy Boolean
     */
    private function _getPostByGUID() {
        $PostGUID = strtolower(NoNull($this->settings['guid'], $this->settings['PgSub1']));
        if ( mb_strlen($PostGUID) != 36 ) { $this->_setMetaMessage("Invalid Post Identifier Supplied (1)", 400); return false; }

        $PostID = $this->_getPostIDFromGUID($PostGUID);
        if ( $PostID > 0 ) { return $this->_getPostsByIDs($PostID); }

        // If We're Here, the Post.guid Was Not Found (or is Inaccessible)
        $this->_setMetaMessage("Invalid Post Identifier Supplied (2)", 400);
        return false;
    }

    /**
     *  Function returns a Collection of Posts based on the Post.GUID of a Single Post in a Thread Supplied or an Unhappy Boolean
     */
    private function _getThreadByGUID() {
        $PostGUID = strtolower(NoNull($this->settings['guid'], $this->settings['PgSub1']));
        $SimpleHtml = YNBool(NoNull($this->settings['simple']));

        if ( mb_strlen($PostGUID) != 36 ) { $this->_setMetaMessage("Invalid Thread Identifier Supplied (1)", 400); return false; }

        $ReplStr = array( '[POST_GUID]' => sqlScrub($PostGUID) );
        $sqlStr = readResource(SQL_DIR . '/posts/getThreadPostIDs.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $PostIDs = array();
            foreach ( $rslt as $Row ) {
                $PostIDs[] = nullInt($Row['post_id']);
            }

            if ( count($PostIDs) > 0 ) {
                $posts = $this->_getPostsByIDs(implode(',', $PostIDs));
                if ( is_array($posts) ) {
                    $data = array();
                    $reply_url = false;

                    foreach ( $posts as $post ) {
                        if ( $SimpleHtml === false ) {
                            $html = $this->_buildHTMLElement(array(), $post);
                            $ReplStr = array( '  ' => ' ', "\n <" => "\n<" );
                            for ( $i = 0; $i < 100; $i++ ) {
                                $html = str_replace(array_keys($ReplStr), array_values($ReplStr), $html);
                            }
                            $post['html'] = NoNull($html);
                        }

                        if ( $post['guid'] == $PostGUID ) { $reply_url = $post['reply_to']; }
                        $post['is_selected'] = (($post['guid'] == $PostGUID) ? true : false);
                        $post['is_reply_to'] = (($reply_url !== false && $reply_url == $post['canonical_url']) ? true : false);

                        $data[] = $post;
                    }

                    // Return the Data If We Have It
                    if ( count($data) > 0 ) { return $data; }
                }
            }
        }

        // If We're Here, the Post.guid Was Not Found (or is Inaccessible)
        $this->_setMetaMessage("Invalid Thread Identifier Supplied (2)", 400);
        return false;
    }

    /**
     *  Function Returns a Channel GUID for a Given PostGUID. If none is found, an unhappy boolean is returned.
     */
    private function _getPostChannel( $PostGUID ) {
        if ( mb_strlen(NoNull($PostGUID)) <= 30 ) { return false; }

        // Build the SQL Query and execute it
        $ReplStr = array( '[POST_GUID]' => sqlScrub($PostGUID) );
        $sqlStr = readResource(SQL_DIR . '/posts/getPostChannel.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            foreach ( $rslt as $Row ) {
                return NoNull($Row['channel_guid']);
            }
        }

        // If We're Here, There Is No Matching Post
        return false;
    }

    private function _getPostMentions( $post_ids ) {
        $list = array();

        // If We've Received a List, Split it Out
        if ( is_array($post_ids) ) {
            foreach ( $post_ids as $id ) {
                $list[] = nullInt($id);
            }
        }

        // If a Single Post is Being Requested, Check to See If It's in Memory
        if ( nullInt($post_ids) > 0 ) {
            if ( is_array($this->settings["post-$post_ids"]) ) {
                return $this->settings["post-$post_ids"];
            }
            $list[] = nullInt($post_ids);
        }
        if ( count($list) <= 0 ) { return false; }

        $ReplStr = array( '[POST_IDS]'   => sqlScrub(implode(',', $list)),
                          '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPostMentions.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            if ( array_key_exists('personas', $this->settings) === false ) {
                $this->settings['personas'] = array();
                $this->settings['pa_guids'] = array();
            }

            foreach ( $rslt as $Row ) {
                $pid = nullInt($Row['post_id']);
                if ( is_array($this->settings["post-$pid"]) === false ) {
                    $this->settings["post-$pid"] = array();
                }

                if ( in_array(NoNull($Row['name']), $this->settings['personas']) === false ) {
                    $this->settings['personas'][] = NoNull($Row['name']);
                    $this->settings['pa_guids'][] = array( 'guid' => NoNull($Row['guid']),
                                                           'name' => '@' . NoNull($Row['name']),
                                                          );
                }

                // Write the Record to the Cache
                $this->settings["post-$pid"] = array( 'guid'   => NoNull($Row['guid']),
                                                      'as'     => '@' . NoNull($Row['name']),
                                                      'is_you' => YNBool($Row['is_you']),
                                                     );
            }

            // If We Have Data, Return It
            if ( is_array($post_ids) ) {
                return count($rslt);

            } else {
                if ( is_array($this->settings["post-$post_ids"]) ) {
                    return $this->settings["post-$post_ids"];
                }
            }
        }

        // If We're Here, There's Nothing
        return false;
    }

    private function _parsePostMentions( $text ) {
        if ( is_array($this->settings['pa_guids']) === false ) { return $text; };
        $userlist = $this->settings['pa_guids'];

        $ReplStr = array();
        if ( is_array($userlist) ) {
            foreach ( $userlist as $u ) {
                $ReplStr[ $u['name'] . '</' ]  = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span></';
                $ReplStr[ $u['name'] . '<br' ] = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span><br';
                $ReplStr[ $u['name'] . '<hr' ] = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span><hr';
                $ReplStr[ $u['name'] . '?' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>?';
                $ReplStr[ $u['name'] . '!' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>!';
                $ReplStr[ $u['name'] . '.' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>.';
                $ReplStr[ $u['name'] . ':' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>:';
                $ReplStr[ $u['name'] . ';' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>;';
                $ReplStr[ $u['name'] . ',' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>,';
                $ReplStr[ $u['name'] . ' ' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span> ';
                $ReplStr[ $u['name'] . ')' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>)';
                $ReplStr[ $u['name'] . "'" ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . "</span>'";
                $ReplStr[ $u['name'] . "’" ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . "</span>’";
                $ReplStr[ $u['name'] . '-' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>-';
                $ReplStr[ $u['name'] . '"' ]   = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . '</span>"';
                $ReplStr[ $u['name'] . "\n" ]  = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . "</span>\n";
                $ReplStr[ $u['name'] . "\r" ]  = '<span class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . "</span>\r";
                $ReplStr[ "\n" . $u['name'] ]  = "\n<span" . ' class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . "</span>";
                $ReplStr[ "\r" . $u['name'] ]  = "\r<span" . ' class="account" data-guid="' . $u['guid'] . '">' . $u['name'] . "</span>";
            }
        }

        // Parse and Return the Text
        $rVal = NoNull(str_ireplace(array_keys($ReplStr), array_values($ReplStr), " $text "));
        return NoNull($rVal, $text);
    }

    /**
     *  Function Returns the Posts Requested
     */
    private function _getPostsByIDs( $PostIDs = false ) {
        $ids = array();
        if ( is_bool($PostIDs) ) { $this->_setMetaMessage("Invalid Post IDs Supplied", 400); return false; }
        if ( is_string($PostIDs) ) {
            $list = explode(',', $PostIDs);
            foreach ( $list as $id ) {
                if ( in_array($id, $ids) === false && nullInt($id) > 0 ) { $ids[] = nullInt($id); }
            }
        }
        if ( is_array($PostIDs) ) {
            foreach ( $PostIDs as $id ) {
                if ( in_array($id, $ids) === false && nullInt($id) > 0 ) { $ids[] = nullInt($id); }
            }
        }
        if ( count($ids) <= 0 && is_numeric($PostIDs) ) { $ids[] = nullInt($PostIDs); }

        // If There Are Zero IDs in the Array, We Were Given Non-Numerics
        if ( count($ids) <= 0 ) { $this->_setMetaMessage("Invalid Post IDs Supplied", 400); return false; }

        // Glue the Post IDs back Together
        $posts = implode(',', $ids);

        // Get the Persona.GUID (if applicable)
        $PersonaGUID = NoNull($this->settings['persona_guid'], NoNull($this->settings['persona-guid'], $this->settings['_persona_guid']));

        // Construct the Replacement Array and Run the Query
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[PERSONA_GUID]' => sqlScrub($PersonaGUID),
                          '[POST_IDS]'     => sqlScrub($posts),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPostsByIDs.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $data = array();
            $mids = array();

            // Collect the Mentions
            foreach ( $rslt as $Row ) {
                if ( YNBool($Row['has_mentions']) ) {
                    $mids[] = nullInt($Row['post_id']);
                }
            }
            if ( count($mids) > 0 ) {
                $pms = $this->_getPostMentions($mids);
            }

            // Now Let's Parse the Posts
            foreach ( $rslt as $Row ) {
                $siteURL = ((YNBool($Row['https'])) ? 'https' : 'http') . '://' . NoNull($Row['site_url']);
                $cdnURL = $siteURL . '/images/';
                $poMeta = false;
                if ( YNBool($Row['has_meta']) ) { $poMeta = $this->_getPostMeta($Row['post_guid']); }
                if ( NoNull($this->settings['nom']) != '' ) {
                    if ( is_array($poMeta) === false ) { $poMeta = array(); }
                    $poMeta['nom'] = NoNull($this->settings['nom']);
                }
                $poTags = false;
                if ( NoNull($Row['post_tags']) != '' ) {
                    $poTags = array();
                    $tgs = explode(',', NoNull($Row['post_tags']));
                    foreach ( $tgs as $tag ) {
                        $key = $this->_getSafeTagSlug(NoNull($tag));

                        $poTags[] = array( 'url'  => $siteURL . '/tag/' . $key,
                                           'name' => NoNull($tag),
                                          );
                    }
                }

                // Do We Have Mentions? Grab the List
                $mentions = false;
                if ( YNBool($Row['has_mentions']) ) {
                    $mentions = $this->_getPostMentions($Row['post_id']);
                }

                // Determine Which HTML Classes Can Be Applied to the Record
                $pguid = NoNull($this->settings['PgSub1'], $this->settings['guid']);
                if ( mb_strlen($pguid) != 36 ) { $pguid = ''; }
                $pclass = array();
                if ( NoNull($Row['canonical_url']) == NoNull($this->settings['ReqURI']) || NoNull($Row['post_guid']) == $pguid || count($rslt) == 1 ) { $pclass[] = 'h-entry'; }
                if ( NoNull($Row['reply_to']) != '' ) { $pclass[] = 'p-in-reply-to'; }

                $IsNote = true;
                if ( in_array(NoNull($Row['post_type']), array('post.article', 'post.quotation', 'post.bookmark')) ) { $IsNote = false; }
                $post_text = $this->_getMarkdownHTML($Row['value'], $Row['post_id'], $IsNote, true);
                $post_text = $this->_parsePostMentions($post_text);

                $data[] = array( 'guid'     => NoNull($Row['post_guid']),
                                 'type'     => NoNull($Row['post_type']),
                                 'thread'   => ((NoNull($Row['thread_guid']) != '') ? array( 'guid' => NoNull($Row['thread_guid']), 'count' => nullInt($Row['thread_posts']) ) : false),
                                 'privacy'  => NoNull($Row['privacy_type']),
                                 'persona'  => array( 'guid'    => NoNull($Row['persona_guid']),
                                                      'as'      => '@' . NoNull($Row['persona_name']),
                                                      'name'    => NoNull($Row['display_name']),
                                                      'avatar'  => $siteURL . '/avatars/' . NoNull($Row['avatar_img'], 'default.png'),
                                                      'follow'  => array( 'url' => $siteURL . '/feeds/' . NoNull($Row['persona_name']) . '.json',
                                                                          'rss' => $siteURL . '/feeds/' . NoNull($Row['persona_name']) . '.xml',
                                                                         ),
                                                      'is_active'    => YNBool($Row['persona_active']),
                                                      'is_you'       => ((nullInt($Row['created_by']) == nullInt($this->settings['_account_id'])) ? true : false),
                                                      'profile_url'  => $siteURL . '/profile/' . NoNull($Row['persona_name']),

                                                      'created_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['persona_created_at'])),
                                                      'created_unix' => strtotime($Row['persona_created_at']),
                                                      'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['persona_updated_at'])),
                                                      'updated_unix' => strtotime($Row['persona_updated_at']),
                                                     ),

                                 'title'    => ((NoNull($Row['title']) == '') ? false : NoNull($Row['title'])),
                                 'content'  => str_replace('[HOMEURL]', $siteURL, $post_text),
                                 'text'     => NoNull($Row['value']),

                                 'publish_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['publish_at'])),
                                 'publish_unix' => strtotime($Row['publish_at']),
                                 'expires_at'   => ((NoNull($Row['expires_at']) != '') ? date("Y-m-d\TH:i:s\Z", strtotime($Row['expires_at'])) : false),
                                 'expires_unix' => ((NoNull($Row['expires_at']) != '') ? strtotime($Row['expires_at']) : false),
                                 'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['updated_at'])),
                                 'updated_unix' => strtotime($Row['updated_at']),

                                 'meta'     => $poMeta,
                                 'tags'     => $poTags,
                                 'mentions' => $mentions,

                                 'canonical_url'    => $siteURL . NoNull($Row['canonical_url']),
                                 'slug'             => NoNull($Row['slug']),
                                 'reply_to'         => ((NoNull($Row['reply_to']) == '') ? false : NoNull($Row['reply_to'])),
                                 'class'            => ((count($pclass) > 0) ? implode(' ', $pclass) : ''),
                                 'attributes'       => array( 'pin'     => NoNull($Row['pin_type'], 'pin.none'),
                                                              'starred' => YNBool($Row['is_starred']),
                                                              'muted'   => YNBool($Row['is_muted']),
                                                              'points'  => nullInt($Row['points']),
                                                             ),

                                 'channel'  => array( 'guid'    => NoNull($Row['channel_guid']),
                                                      'name'    => NoNull($Row['channel_name']),
                                                      'type'    => NoNull($Row['channel_type']),
                                                      'privacy' => NoNull($Row['channel_privacy_type']),

                                                      'created_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['channel_created_at'])),
                                                      'created_unix' => strtotime($Row['channel_created_at']),
                                                      'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($Row['channel_updated_at'])),
                                                      'updated_unix' => strtotime($Row['channel_updated_at']),
                                                     ),
                                 'site'     => array( 'guid'        => NoNull($Row['site_guid']),
                                                      'name'        => NoNull($Row['site_name']),
                                                      'description' => NoNull($Row['site_description']),
                                                      'keywords'    => NoNull($Row['site_keywords']),
                                                      'url'         => $siteURL
                                                     ),
                                 'client'   => array( 'guid'    => NoNull($Row['client_guid']),
                                                      'name'    => NoNull($Row['client_name']),
                                                      'logo'    => $cdnURL . NoNull($Row['client_logo_img']),
                                                     ),

                                 'can_edit' => ((nullInt($Row['created_by']) == nullInt($this->settings['_account_id'])) ? true : false),
                                );
            }

            // If We Have Data, Return It
            if ( count($data) > 0 ) { return $data; }
        }

        // If We're Here, There Are No Posts. Return an Empty Array.
        return array();
    }

    /**
     *  Function Writes or Updates a Post Object
     */
    private function _writePost() {
        $data = $this->_validateWritePostData();
        if ( is_array($data) === false ) { return false; }

        // Prep the Replacement Array and Execute the INSERT or UPDATE
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[CHANNEL_GUID]' => sqlScrub($data['channel_guid']),
                          '[PERSONA_GUID]' => sqlScrub($data['persona_guid']),
                          '[TOKEN_GUID]'   => sqlScrub($data['token_guid']),
                          '[TOKEN_ID]'     => nullInt($data['token_id']),

                          '[TITLE]'        => sqlScrub($data['title']),
                          '[VALUE]'        => sqlScrub($data['value']),

                          '[CANON_URL]'    => sqlScrub($data['canonical_url']),
                          '[REPLY_TO]'     => sqlScrub($data['reply_to']),

                          '[POST_SLUG]'    => sqlScrub($data['slug']),
                          '[POST_TYPE]'    => sqlScrub($data['type']),
                          '[PRIVACY]'      => sqlScrub($data['privacy']),
                          '[PUBLISH_AT]'   => sqlScrub($data['publish_at']),
                          '[EXPIRES_AT]'   => sqlScrub($data['expires_at']),

                          '[THREAD_ID]'    => nullInt($data['thread_id']),
                          '[PARENT_ID]'    => nullInt($data['parent_id']),
                          '[POST_ID]'      => nullInt($data['post_id']),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/writePost.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);

        // If It's Good, Record the Meta Data & Collect the Post Object to Return
        if ( nullInt($data['post_id'], $rslt) >= 1 ) {
            $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                              '[CHANNEL_GUID]' => sqlScrub($data['channel_guid']),
                              '[PERSONA_GUID]' => sqlScrub($data['persona_guid']),
                              '[TOKEN_GUID]'   => sqlScrub($data['token_guid']),
                              '[TOKEN_ID]'     => nullInt($data['token_id']),

                              '[POST_ID]'      => nullInt($data['post_id'], $rslt),
                             );

            // Update the Site Version ID (Unfortunately, This Should Not Be In a Trigger)
            $sqlStr = readResource(SQL_DIR . '/posts/updateSiteVersion.sql', $ReplStr) . SQL_SPLITTER;

            // Clear the MetaData for the Post
            $sqlStr .= readResource(SQL_DIR . '/posts/resetPostMeta.sql', $ReplStr);

            // Record the MetaData for the Post
            foreach ( $data['meta'] as $Key=>$Value ) {
                if ( NoNull($Value) != '' ) {
                    $ReplStr = array( '[POST_ID]' => nullInt($data['post_id'], $rslt),
                                      '[VALUE]'   => sqlScrub($Value),
                                      '[KEY]'     => sqlScrub($Key),
                                     );
                    if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                    $sqlStr .= readResource(SQL_DIR . '/posts/writePostMeta.sql', $ReplStr);
                }
            }

            // Clear the Tags for the Post
            if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
            $sqlStr .= readResource(SQL_DIR . '/posts/resetPostTags.sql', $ReplStr);

            // Record the Tags for the Post
            if ( NoNull($data['tags']) != '' ) {
                $tgs = explode(',', NoNull($data['tags']));
                $lst = '';
                foreach ( $tgs as $Value ) {
                    $pid = nullInt($data['post_id'], $rslt);
                    $Key = $this->_getSafeTagSlug(NoNull($Value));
                    $Val = sqlScrub($Value);

                    if ( $lst != '' ) { $lst .= ","; }
                    $lst .= "($pid, '$Key', '$Val')";
                }

                // Extract the Tags from Inside the Post Text
                $lst_hash = $this->_getTagsFromPost($data['value'], nullInt($data['post_id'], $rslt));
                if ( $lst_hash != '' ) { $lst .= ",$lst_hash"; }

                if ( NoNull($lst) != '' ) {
                    $ReplStr = array( '[POST_ID]' => nullInt($data['post_id'], $rslt),
                                      '[VALUE_LIST]' => NoNull($lst)
                                     );
                    if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                    $sqlStr .= readResource(SQL_DIR . '/posts/writePostTags.sql', $ReplStr);
                }
            }

            // Clear the Mentions for the Post
            if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
            $sqlStr .= readResource(SQL_DIR . '/posts/resetPostMentions.sql', $ReplStr);

            // Record any Mentions for the Post
            $pids = $this->_getMentionsFromPost($data['value'], nullInt($data['post_id'], $rslt));
            if ( NoNull($pids) != '' ) {
                $ReplStr = array( '[VALUE_LIST]' => NoNull($pids) );
                if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
                $sqlStr .= readResource(SQL_DIR . '/posts/writePostMentions.sql', $ReplStr);
            }

            // Remove Any Duplicate Posts (If Applicable)
            $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']) );
            if ( $sqlStr != '' ) { $sqlStr .= SQL_SPLITTER; }
            $sqlStr .= readResource(SQL_DIR . '/posts/chkPostDupes.sql', $ReplStr);

            // Execute the Queries
            $isOK = doSQLExecute($sqlStr);

            // Send any Webmentions or Pingbacks (If Applicable)
            $this->_setPostPublishData(nullInt($data['post_id'], $rslt));

            // Collect the Post Object
            return $this->_getPostsByIDs(nullInt($data['post_id'], $rslt));
        }

        // If We're Here, There's a Problem
        $this->_setMetaMessage("Could Not Write Post to Database", 400);
        return false;
    }

    private function _preparePostAction() {
        $PersonaGUID = NoNull($this->settings['persona_guid']);
        $PostGUID = NoNull($this->settings['post_guid'], NoNull($this->settings['guid'], $this->settings['PgSub1']));

        // Ensure we Have the requisite GUIDs
        if ( mb_strlen($PersonaGUID) <= 30 ) { return false; }
        if ( mb_strlen($PostGUID) <= 30 ) { return false; }

        // Build and Run the SQL Query
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[POST_GUID]'    => sqlScrub($PostGUID),
                          '[PERSONA_GUID]' => sqlScrub($PersonaGUID),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/preparePostAction.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);

        // If We Have a Result, It's Good
        if ( $rslt ) { return true; }
        return false;
    }

    private function _setPostStar() {
        $PersonaGUID = NoNull($this->settings['persona_guid']);
        $PostGUID = NoNull($this->settings['post_guid'], NoNull($this->settings['guid'], $this->settings['PgSub1']));
        $ReqType = NoNull(strtolower($this->settings['ReqType']));

        // Ensure we Have the requisite GUIDs
        if ( mb_strlen($PersonaGUID) <= 30 ) { $this->_setMetaMessage("Invalid Persona GUID Supplied", 400); return false; }
        if ( mb_strlen($PostGUID) <= 30 ) { $this->_setMetaMessage("Invalid Post GUID Supplied", 400); return false; }
        $this->settings['guid'] = $PostGUID;
        $this->settings['ReqType'] = 'GET';

        // Prep the Action Record (if applicable)
        $sOK = $this->_preparePostAction();

        // Build and Run the SQL Query
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[POST_GUID]'    => sqlScrub($PostGUID),
                          '[PERSONA_GUID]' => sqlScrub($PersonaGUID),
                          '[VALUE]'        => sqlScrub(BoolYN(($ReqType == 'post'))),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/setPostStar.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);

        // Return the Updated Post
        return $this->_getPostByGUID();
    }

    /** ********************************************************************* *
     *  MetaData Functions
     ** ********************************************************************* */
    /**
     *  Function Returns the MetaData for a Given Post.guid using mostly-consistent array structures.
     *      Dynamic keys can be used as well, so long as they're consistently applied.
     */
    private function _getPostMeta( $PostGUID ) {
        if ( mb_strlen(NoNull($PostGUID)) != 36 ) { return false; }

        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[POST_GUID]'  => sqlScrub($PostGUID),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPostMeta.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $data = array();
            foreach ( $rslt as $Row ) {
                if ( YNBool($Row['is_visible']) ) {
                    $block = explode('_', $Row['key']);
                    if ( is_array($data[$block[0]]) === false ) {
                        $data[$block[0]] = $this->_getPostMetaArray($block[0]);
                    }
                    $data[$block[0]][$block[1]] = (is_numeric($Row['value']) ? nullInt($Row['value']) : NoNull($Row['value']));
                }
            }
            if ( count($data) > 0 ) { return $data; }
        }

        // If We're Here, There's No Meta
        return false;
    }

    private function _getPostMetaArray( $KeyPrefix ) {
        $CleanKey = strtolower($KeyPrefix);
        switch ( $CleanKey ) {
            case 'geo':
                return array( 'longitude' => false,
                              'latitude'  => false,
                              'altitude'  => false
                             );
                break;

            case 'source':
                return array( 'url'     => false,
                              'title'   => false,
                              'summary' => false,
                              'author'  => false
                             );
                break;

            default:
                return array();
        }
    }

    /**
     *  Function Looks for Hashtags in a Post and Returns a Comma-Separated List
     */
    private function _extractPostTags( $Text ) {
        $rVal = strip_tags($Text);
        $words = explode(' ', " $rVal ");
        $hh = array();

        foreach ( $words as $word ) {
            $clean_word = NoNull(strip_tags($word));
            $hash = '';

            if ( NoNull(substr($clean_word, 0, 1)) == '#' ) {
                $hash_scrub = array('#', '?', '.', ',', '!', '<', '>');
                $hash = NoNull(str_replace($hash_scrub, '', $clean_word));

                if ($hash != '' && in_array($hash, $hh) === false ) { $hh[] = $hash; }
            }
        }

        // Return the List of Hashes
        return implode(',', $hh);
    }

    /**
     *  Function Gets the Post.id Value based on the GUID supplied.
     *  Notes:
     *      * If the account does not have Read permissions to the channel, then the Post.id Value cannot be returned
     *      * If the Post has a higher Privacy setting, the Account requesting the Post must have Read permissions
     *      * If the HTTP Request Type is an Edit action, the Account must own the Persona the Post was Saved under
     */
    private function _getPostIDFromGUID( $Guid ) {
        if ( mb_strlen(NoNull($Guid)) != 36 ) { return 0; }
        $edits = array('post', 'put', 'delete');

        // Construct the SQL Query
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[POST_GUID]'    => sqlScrub($Guid),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPostIDFromGuid.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $ReqType = NoNull(strtolower($this->settings['ReqType']));
            foreach ( $rslt as $Row ) {
                if ( YNBool($Row['can_read']) && nullInt($Row['post_id']) > 0 ) {
                    if ( in_array($ReqType, $edits) && YNBool($Row['can_write']) === false ) { return 0; }
                    return nullInt($Row['post_id']);
                }
            }
        }

        // If We're Here, No Post Was Found. Return Zero.
        return 0;
    }

    /**
     *  Function Deletes a Post and all of its Related Details from the Database
     */
    private function _deletePost() {
        $PostGUID = NoNull($this->settings['PgSub1'], $this->settings['post_guid']);
        $isOK = false;

        // Ensure we Have a GUID
        if ( mb_strlen($PostGUID) <= 30 ) { $this->_setMetaMessage("Invalid Post GUID Supplied", 400); return false; }

        // Collect the Channel.GUID Value for the Post
        $ChannelGUID = $this->_getPostChannel($PostGUID);
        if ( $ChannelGUID === false ) { $this->_setMetaMessage("Invalid Post GUID Supplied", 400); return false; }

        // Remove the Record from the Database
        $ReplStr = array( '[ACCOUNT_ID]'   => nullInt($this->settings['_account_id']),
                          '[POST_GUID]'    => sqlScrub($PostGUID),
                          '[CHANNEL_GUID]' => sqlScrub($ChannelGUID),
                          '[SQL_SPLITTER]' => sqlScrub(SQL_SPLITTER),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/deletePost.sql', $ReplStr);
        $rslt = doSQLExecute($sqlStr);
        if ( $rslt ) {
            $sqlStr = readResource(SQL_DIR . '/posts/updateSiteVersion.sql', $ReplStr);
            $isOK = doSQLExecute($sqlStr);
        }

        // Return an Array of Data
        return array( 'post_guid'    => $PostGUID,
                      'channel_guid' => $ChannelGUID,

                      'result' => $rslt,
                      'sok'    => $isOK,
                     );
    }

    /** ********************************************************************* *
     *  Data Validation Functions
     ** ********************************************************************* */
    /**
     *  Function Determines if the Variable Set Supplied is Valid for the Requirements of a Given Type
     *      and Returns an Array of Information or a Single, Unhappy Boolean
     */
    private function _validateWritePostData() {
        $ChannelGUID = NoNull($this->settings['channel_guid']);
        $PersonaGUID = NoNull($this->settings['persona_guid']);
        $Title = NoNull($this->settings['post_title'], $this->settings['title']);
        $Value = NoNull($this->settings['post_text'], NoNull($this->settings['text'], $this->settings['content']));
        $CanonURL = NoNull($this->settings['canonical_url'], $this->settings['post_url']);
        $ReplyTo = NoNull($this->settings['post_reply_to'], $this->settings['reply_to']);
        $PostSlug = NoNull($this->settings['post_slug'], $this->settings['slug']);
        $PostType = NoNull($this->settings['post_type'], NoNull($this->settings['type'], 'post.draft'));
        $Privacy = NoNull($this->settings['post_privacy'], $this->settings['privacy']);
        $PublishAt = NoNull($this->settings['post_publish_at'], $this->settings['publish_at']);
        $ExpiresAt = NoNull($this->settings['post_expires_at'], $this->settings['expires_at']);
        $PostTags = NoNull($this->settings['post_tags'], $this->settings['tags']);
        $PostGUID = NoNull($this->settings['post_guid'], NoNull($this->settings['guid'], $this->settings['PgSub1']));
        $PostID = $this->_getPostIDFromGUID($PostGUID);

        // More Elements
        $ParentID = 0;
        $ThreadID = 0;

        // Additional Meta
        $SourceURL = NoNull($this->settings['source_url'], $this->settings['source']);
        $SourceTitle = NoNull($this->settings['source_title']);
        $GeoLong = NoNull($this->settings['geo_longitude'], $this->settings['geo_long']);
        $GeoLat = NoNull($this->settings['geo_latitude'], $this->settings['geo_lat']);
        $GeoAlt = NoNull($this->settings['geo_altitude'], $this->settings['geo_alt']);
        $GeoFull = NoNull($this->settings['post_geo'], $this->settings['geo']);
        if ( $GeoFull != '' ) {
            $coords = explode(',', $GeoFull);
            if ( nullInt($coords[0]) != 0 && nullInt($coords[1]) != 0 ) {
                $GeoLat = nullInt($coords[0]);
                $GeoLong = nullInt($coords[1]);
                if ( nullInt($coords[2]) != 0 ) { $GeoAlt = nullInt($coords[2]); }
            }
        }

        // Check the Post Text for Additionals
        $hash_list = $this->_extractPostTags($Value);
        if ( $hash_list != '' ) {
            if ( $PostTags != '' ) { $PostTags .= ','; }
            $PostTags .= $hash_list;
        }

        // Token Definition
        $TokenGUID = '';
        $TokenID = 0;
        $isValid = true;

        // Get the Token Information
        if ( NoNull($this->settings['token']) != '' ) {
            $data = explode('_', NoNull($this->settings['token']));
            if ( count($data) == 3 ) {
                if ( $data[0] == str_replace('_', '', TOKEN_PREFIX) ) {
                    $TokenGUID = NoNull($data[2]);
                    $TokenID = alphaToInt($data[1]);
                }
            }
        }

        // Validate the Requisite Data
        if ( mb_strlen($ChannelGUID) != 36 ) { $this->_setMetaMessage("Invalid Channel GUID Supplied", 400); $isValid = false; }
        if ( mb_strlen($PersonaGUID) != 36 ) { $this->_setMetaMessage("Invalid Persona GUID Supplied", 400); $isValid = false; }
        if ( $PostType == '' ) { $PostType = 'post.draft'; }
        if ( $Privacy == '' ) { $Privacy = 'visibility.public'; }

        // Ensure the Dates are Set to UTC
        if ( strtotime($PublishAt) === false ) { $PublishAt = ''; }
        if ( strtotime($ExpiresAt) === false ) { $ExpiresAt = ''; }
        if ($PublishAt != '') { $PublishAt = $this->_convertTimeToUTC($PublishAt); }
        if ($ExpiresAt != '') { $ExpiresAt = $this->_convertTimeToUTC($ExpiresAt); }

        switch ( strtolower($PostType) ) {
            case 'post.quotation':
                if ( mb_strlen($Value) <= 0 ) { $this->_setMetaMessage("Please Supply Some Text", 400); $isValid = false; }
                if ( mb_strlen($SourceURL) <= 0 ) { $this->_setMetaMessage("Please Supply a Source URL", 400); $isValid = false; }
                break;

            case 'post.bookmark':
                if ( mb_strlen($SourceURL) <= 0 ) { $this->_setMetaMessage("Please Supply a Source URL", 400); $isValid = false; }
                break;

            case 'post.article':
                if ( $PostSlug == '' ) {
                    $PostSlug = $this->_getSafeTagSlug($Title);

                    // Check if the Slug is Unique
                    $PostSlug = $this->_checkUniqueSlug($ChannelGUID, $PostGUID, $PostSlug);

                    // If the Slug is Not Empty, Set the Canonical URL Value
                    if ( $PostSlug != '' ) { $CanonURL = "/article/$PostSlug"; }
                }
                if ( mb_strlen($Value) <= 0 ) { $this->_setMetaMessage("Please Supply Some Text", 400); $isValid = false; }
                break;

            case 'post.draft':
            case 'post.note':
                if ( mb_strlen($Value) <= 0 ) { $this->_setMetaMessage("Please Supply Some Text", 400); $isValid = false; }
                break;

            default:
                $this->_setMetaMessage("Unknown Post Type: $PostType", 400);
                $isValid = false;
        }

        // If Something Is Wrong, Return an Unhappy Boolean
        if ( $isValid !== true ) { return false; }

        // Can we Identify a ParentID and a Thread Based on the ReplyTo? (If Applicable)
        if ( mb_strlen($ReplyTo) >= 10 ) {
            $guid = '';
            if ( strpos($ReplyTo, '/') >= 0 ) {
                $ups = explode('/', $ReplyTo);
                for ( $i = (count($ups) - 1); $i >= 0; $i-- ) {
                    if ( mb_strlen(NoNull($ups[$i])) == 36 ) { $guid = NoNull($ups[$i]); }
                }

            } else {
                if ( mb_strlen($ReplyTo) == 36 ) { $guid = $ReplyTo; }
            }

            // If We Have a GUID, Let's Check If It's a 10C Object
            $ReplStr = array( '[POST_GUID]' => sqlScrub($guid) );
            $sqlStr = readResource(SQL_DIR . '/posts/chkPostParent.sql', $ReplStr);
            $rslt = doSQLQuery($sqlStr);
            if ( is_array($rslt) ) {
                foreach ( $rslt as $Row ) {
                    $ParentID = nullInt($Row['parent_id']);
                    $ThreadID = nullInt($Row['thread_id']);
                    $ReplyTo = NoNull($Row['post_url']);
                }
            }
        }

        // If We're Here, Build the Return Array
        return array( 'channel_guid'  => $ChannelGUID,
                      'persona_guid'  => $PersonaGUID,
                      'token_guid'    => $TokenGUID,
                      'token_id'      => $TokenID,

                      'title'         => $Title,
                      'value'         => $this->_cleanContent($Value),

                      'canonical_url' => $CanonURL,
                      'reply_to'      => $ReplyTo,

                      'slug'          => $PostSlug,
                      'type'          => $PostType,
                      'privacy'       => $Privacy,

                      'publish_at'    => $PublishAt,
                      'expires_at'    => $ExpiresAt,

                      'tags'          => $PostTags,
                      'meta'          => array( 'source_url'    => $SourceURL,
                                                'source_title'  => $SourceTitle,
                                                'geo_latitude'  => $GeoLat,
                                                'geo_longitude' => $GeoLong,
                                                'geo_altitude'  => $GeoAlt,
                                               ),

                      'thread_id'     => $ThreadID,
                      'parent_id'     => $ParentID,
                      'post_id'       => $PostID,
                     );
    }

    /**
     *  Function Tries Like Heck to Sanitize the Content of a Post to Fit Expectations
     */
    private function _cleanContent( $text ) {
        $ReplStr = array( '//@' => '// @', '<p>' => '', '</p>' => "\r\n", '<strong>' => '**', '</strong>' => '**', '<em>' => '*', '</em>' => '*',
                          '<p class="">' => '', 'ql-align-justify' => '', 'ql-align-center' => '', 'ql-align-right' => '',
                         );

        for ( $i = 0; $i < 5; $i++ ) {
            $text = NoNull(str_replace(array_keys($ReplStr), array_values($ReplStr), $text));
        }

        // Return the Scrubbed Text
        return $text;
    }

    /** ********************************************************************* *
     *  Web-Presentation Functions
     ** ********************************************************************* */
    private function _checkPageCategory() {
        $valids = array('quotation', 'bookmark', 'tag', 'note', 'blog', 'article');
        if ( in_array(strtolower(NoNull($this->settings['PgRoot'])), $valids) ) {
            print_r( "Let's Show Posts From: " . strtolower(NoNull($this->settings['PgRoot'])));
            if ( NoNull($this->settings['PgSub1']) != '' ) { print_r( " -> " . NoNull($this->settings['PgSub1'])); }
            die();
        }
    }

    /**
     *  Function Returns a Tag Key Should One Be Requested
     */
    private function _getTagKey() {
        $valids = array('tag');
        if ( in_array(strtolower(NoNull($this->settings['PgRoot'])), $valids) ) {
            return NoNull($this->settings['PgSub1']);
        }
        return '';
    }

    /**
     *  Function Determines the Current Page URL
     */
    private function _getCanonicalURL() {
        if ( NoNull($this->settings['PgRoot']) == '' ) { return ''; }

        $rVal = '/' . NoNull($this->settings['PgRoot']);
        for ( $i = 1; $i <= 9; $i++ ) {
            if ( NoNull($this->settings['PgSub' . $i]) != '' ) {
                $rVal .= '/' . NoNull($this->settings['PgSub' . $i]);
            } else {
                return $rVal;
            }
        }

        // Return the Canonical URL
        return $rVal;
    }
    private function _getPageNumber() {
        $Page = (nullInt($this->settings['page'], 1) - 1);
        if ( $Page <= 0 ) {
            if ( NoNull($this->settings['PgRoot']) == 'page' ) {
                $Page = (nullInt($this->settings['PgSub1'], 1) - 1);
            }
        }

        // Return the Requested Page Number
        return $Page;
    }

    /**
     *  Function Returns an HTML List of Popular Posts
     *  Note: this caches data for 60 minutes before refreshing
     */
    private function _getPopularPosts() {
        $CacheFile = 'popular-' . date('Ymdh');
        $html = '';

        // Check for a Cache File and Return It If Valid
        if ( defined('ENABLE_CACHING') === false ) { define('ENABLE_CACHING', 0); }
        if ( nullInt(ENABLE_CACHING) == 1 ) { $html = readCache($this->settings['site_id'], $CacheFile); }
        if ( $html !== false && $html != '' ) { return $html; }

        // If We're Here, Let's Construct the Popular Posts List
        $ReplStr = array( '[SITE_ID]' => nullInt($this->settings['site_id']),
                          '[COUNT]'   => nullInt($this->settings['pops_count'], 9),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPopularPosts.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $SiteUrl = NoNull($this->settings['HomeURL']);

            foreach ( $rslt as $Row ) {
                $PublishAt = date("Y-m-d\TH:i:s\Z", strtotime($Row['publish_at']));

                if ( $html != '' ) { $html .= "\r\n"; }
                $html .= tabSpace(4) . '<div class="col-lg-4 col-md-4 col-sm-4">' . "\r\n" .
                         tabSpace(5) . '<div class="page-footer__recent-post">' . "\r\n" .
                         tabSpace(6) . '<a href="' . $SiteUrl . NoNull($Row['canonical_url']) . '" data-views="' . nullInt($Row['hits']) . '">' . NoNull($Row['title'], $Row['type']) . '</a>' . "\r\n" .
                         tabSpace(6) . '<div class="page-footer__recent-post-date">' . "\r\n" .
                         tabSpace(7) . '<span class="dt-published" datetime="' . $PublishAt . '" data-dateunix="' . strtotime($Row['publish_at']) . '">' . NoNull($PublishAt, $Row['publish_at']) . '</span>' . "\r\n" .
                         tabSpace(6) . '</div>' . "\r\n" .
                         tabSpace(5) . '</div>' . "\r\n" .
                         tabSpace(4) . '</div>';
            }

            // Save the File to Cache if Required
            if ( nullInt(ENABLE_CACHING) == 1 ) { saveCache($this->settings['site_id'], $CacheFile, $html); }

            // Return the List of Popular Posts
            return $html;
        }

        // If We're Here, There's Nothing.
        return '';
    }

    /**
     *  Function Determines the Pagination for the Page
     */
    private function _getPagePagination( $data ) {
        $CanonURL = $this->_getCanonicalURL();
        $PgRoot = strtolower(NoNull($this->settings['PgRoot']));
        $Count = nullInt($this->settings['count'], 10);
        $Page = $this->_getPageNumber();
        $Page++;
        $html = '';

        // If We're Writing a New Post, Return a Different Set of Data
        if ( NoNull($this->settings['PgRoot']) == 'new' && NoNull($this->settings['PgSub1']) == '' ) {
            return '';
        }

        // Determine the Name of the Cache File (if Required)
        $CacheFile = substr(str_replace('/', '-', NoNull($CanonURL, '/home')), 1) . '-' . $Page . '-' . NoNull($data['site_version'], 'ver0');
        $CacheFile = str_replace('--', '-', $CacheFile);

        if ( defined('ENABLE_CACHING') === false ) { define('ENABLE_CACHING', 0); }
        if ( nullInt(ENABLE_CACHING) == 1 ) { $html = readCache($this->settings['site_id'], $CacheFile); }
        if ( $html !== false && $html != '' ) { return $html; }
        $tObj = strtolower(str_replace('/', '', $CanonURL));

        // Construct the SQL Query
        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[SITE_GUID]'  => sqlScrub($data['site_guid']),
                          '[CANON_URL]'  => sqlScrub($CanonURL),
                          '[PGROOT]'     => sqlScrub($PgRoot),
                          '[OBJECT]'     => sqlScrub($tObj),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPagePagination.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $max = 0;
            $cnt = 0;

            foreach ( $rslt as $Row ) {
                if ( YNBool($Row['exacts']) === false ) {
                    $cnt = nullInt($Row['post_count']);
                }
            }
            while ( $cnt > 0 ) {
                $cnt -= $Count;
                if ( $cnt > 0 ) { $max++; }
            }
            $max++;

            // If the Maximum Page is Greater Than 1, Build a Pagination Matrix
            if ( $max > 1 ) {
                $SiteUrl = NoNull($this->settings['HomeURL']);
                if ( $PgRoot == $tObj ) { $SiteUrl .= "/$PgRoot"; }
                if ( $Page <= 0 ) { $Page = 1; }
                $cnt = 1;

                if ( $Page > 1 ) {
                    if ( $html != '' ) { $html .= "\r\n"; }
                    $html .= tabSpace(6) . 
                             '<li class="blog-pagination__item">' .
                                '<a href="' . $SiteUrl . '?page=' . ($Page - 1) . '"><i class="fa fa-backward"></i></a>' .
                             '</li>';
                }

                $min_idx = ($Page - 4);
                if ( $min_idx < 1 ) { $min_idx = 1; }
                if ( $Page > 7 ) { $min_idx - 4; }
                $max_idx = ($min_idx + 8);
                $min_dot = false;
                $max_dot = false;

                while ( $cnt <= $max ) {
                    if ( ($cnt >= $min_idx && $cnt <= $max_idx) || $cnt == 1 || $cnt == $max ) {
                        if ( $html != '' ) { $html .= "\r\n"; }
                        if ( $cnt == $Page ) {                        
                            $html .= tabSpace(6) . 
                                     '<li class="blog-pagination__item blog-pagination__item--active">' .
                                        '<a>' . number_format($cnt, 0) . '</a>' .
                                     '</li>';

                        } else {
                            $html .= tabSpace(6) . 
                                     '<li class="blog-pagination__item">' .
                                        '<a href="' . $SiteUrl . (($cnt > 1) ? '?page=' . $cnt : '') . '">' . number_format($cnt, 0) . '</a>' .
                                     '</li>';
                        }
                    }
                    if ( $Page > 6 && $cnt < $min_idx && $min_idx > 1 && $min_dot === false ) {
                        if ( $html != '' ) { $html .= "\r\n"; }
                        $html .= tabSpace(6) . 
                                 '<li class="blog-pagination__item">' .
                                    '<a><i class="fa fa-ellipsis-h"></i></a>' .
                                 '</li>';
                        $min_dot = true;
                    }
                    if ( $cnt > $max_idx && $max_idx < $max && $max_dot === false ) {
                        if ( $html != '' ) { $html .= "\r\n"; }
                        $html .= tabSpace(6) . 
                                 '<li class="blog-pagination__item">' .
                                    '<a><i class="fa fa-ellipsis-h"></i></a>' .
                                 '</li>';
                        $max_dot = true;
                    }
                    $cnt++;
                }

                if ( $Page < $max ) {
                    if ( $html != '' ) { $html .= "\r\n"; }
                    $html .= tabSpace(6) . 
                             '<li class="blog-pagination__item">' .
                                '<a href="' . $SiteUrl . '?page=' . ($Page + 1) . '"><i class="fa fa-forward"></i></a>' .
                             '</li>';
                }

                // Format the Complete HTML
                $html = "\r\n" .
                        tabSpace(4) . '<nav class="blog-pagination">' . "\r\n" .
                        tabSpace(5) . '<ul class="blog-pagination__items">' . "\r\n" .
                        $html . "\r\n" .
                        tabSpace(5) . '</ul>' . "\r\n" .
                        tabSpace(4) . '</nav>';

                // Save the File to Cache if Required
                if ( nullInt(ENABLE_CACHING) == 1 ) { saveCache($this->settings['site_id'], $CacheFile, $html); }

                // Return the HTML
                return $html;
            }
        }

        // If We're Here, There's No Pagination Required
        return '';
    }

    private function _getPageHTML( $data ) {
        $Count = nullInt($this->settings['count'], 10);
        $Page = $this->_getPageNumber() * $Count;
        $CanonURL = $this->_getCanonicalURL();
        $TagKey = $this->_getTagKey();
        $tObj = strtolower(str_replace('/', '', $CanonURL));

        // If We're Writing a New Post, Return a Different Set of Data
        if ( NoNull($this->settings['PgRoot']) == 'new' && NoNull($this->settings['PgSub1']) == '' ) {
            return '';
        }

        // Construct the SQL Query
        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[SITE_GUID]'  => sqlScrub($data['site_guid']),
                          '[CANON_URL]'  => sqlScrub($CanonURL),
                          '[TAG_KEY]'    => sqlScrub($TagKey),
                          '[OBJECT]'     => sqlScrub($tObj),
                          '[COUNT]'      => nullInt($Count),
                          '[PAGE]'       => nullInt($Page),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getPagePostIDs.sql', $ReplStr);
        if ( $TagKey != '' ) { $sqlStr = readResource(SQL_DIR . '/posts/getTagPostIDs.sql', $ReplStr); }
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            $ids = '';
            foreach ( $rslt as $Row ) {
                if ( $ids != '' ) { $ids .= ', '; }
                $ids .= nullInt($Row['post_id']);
            }
        }

        $posts = $this->_getPostsByIDs($ids);
        if ( is_array($posts) && count($posts) > 0 ) {
            $html = '';

            foreach ( $posts as $post ) {
                $el = $this->_buildHTMLElement($data, $post);
                if ( $el != '' ) {
                    $postClass = NoNull($post['class']);
                    if ( $postClass != '' ) { $postClass .= ' '; }

                    // Determine the Template File
                    $flatFile = THEME_DIR . '/' . $data['location'] . '/flats/' . $post['type'] . '.html';

                    if ( !file_exists($flatFile) ) {
                        $postClass .= 'post-entry post ' . str_replace('.', '-', $post['type']);
                        $el = tabSpace(4) . '<li class="' . $postClass  . '" data-guid="' . NoNull($post['guid']) . '" data-starred="' . BoolYN($post['attributes']['starred']) . '" data-owner="' . BoolYN($post['can_edit']) . '">' . "\r\n" .
                                            "$el\r\n" .
                              tabSpace(4) . '</li>';
                    }
                    if ( $html != '' ) { $html .= "\r\n"; }
                    $html .= $el;
                }
            }

            // Add the Pagination (If Required)
            if ( $html != '' ) { $html .= $this->_getPagePagination($data); }

            // If There are No Posts, Show a Friendly Message
            if ( NoNull($html) == '' ) { $html = "There Is No HTML Here"; }

            // Return the Completed HTML
            return $html;
        }

        // If We're Here, There's Nothing to Show
        $flatFile = THEME_DIR . '/' . $data['location'] . '/flats/post.welcome.html';
        if ( !file_exists($flatFile) ) { $flatFile = FLATS_DIR .'/templates/post.welcome.html'; }
        return readResource($flatFile, $ReplStr);
    }

    /**
     *  Function Constructs a Standardized HTML Element and Returns the Object or an Empty String
     */
    private function _buildHTMLElement($data, $post) {
        if ( is_array($post) ) {
            // Check to See If We Have a Cached Version of the Post
            $cache_file = md5($data['site_version'] . '-' . NoNull(APP_VER . CSS_VER) . '-' .
                              nullInt($this->settings['_account_id']) . '-' . $this->settings['ReqURI'] . '-' . NoNull($post['guid']));
            if ( nullInt(ENABLE_CACHING) == 1 ) {
                $html = readCache($data['site_id'], $cache_file);
                if ( $html !== false ) { return $html; }
            }

            // If We're Here, We Need to Build an HTML String and Cache It
            $tagLine = '';
            if ( is_array($post['tags']) ) {
                foreach ( $post['tags'] as $tag ) {
                    $tagLine .= '<li><a href="' . NoNull($tag['url']) . '" class="p-category">' . NoNull($tag['name']) . '</a></li>';
                }
            }
            $geoLine = '';
            if ( YNBool(BoolYN($data['show_geo'])) ) {
                if ( is_array($post['meta']) ) {
                    if ( is_array($post['meta']['geo']) ) {
                        if ( $this->geo === false ) {
                            require_once(LIB_DIR . '/geocode.php');
                            $this->geo = new Geocode($this->settings, $this->strings);
                        }

                        $coords = round(nullInt($post['meta']['geo']['latitude']), 7) . ',' . round(nullInt($post['meta']['geo']['longitude']), 7);
                        $label = $this->geo->getNameFromCoords($post['meta']['geo']['latitude'], $post['meta']['geo']['longitude']);

                        $geoLine = "\r\n" .
                                   tabSpace(6) . '<div class="metaline location pad" data-value="' . $coords . '">' . "\r\n" .
                                   tabSpace(7) . '<i class="fas fa-map-pin"></i> <small>' . $label . "</small>\r\n" .
                                   tabSpace(6) . '</div>';
                    }
                }
            }

            $ReplyHTML = '';
            $postClass = NoNull($post['class']);
            if ( $postClass != '' ) { $postClass .= ' '; }
            if ( NoNull($post['reply_to']) != '' ) {
                $replyUrl = parse_url($post['reply_to'], PHP_URL_HOST);
                $ReplyHTML = '<p class="in-reply-to reply-pointer"><i class="fab fa-replyd"></i> <a target="_blank" href="' . NoNull($post['reply_to']) . '" class="p-name u-url">' . NoNull($replyUrl, $post['reply_to']) . '</a>.';
            }

            $PostThread = '';
            if ( is_array($post['thread']) ) {
                if ( nullInt($post['thread']['count']) > 1 ) {
                    $PostThread = ' data-thread-guid="' . NoNull($post['thread']['guid']) . '" data-thread-count="' . nullInt($post['thread']['count']) . '"';
                }
            }

            $SourceIcon = '';
            if ( array_key_exists('meta', $post) && is_array($post['meta']) ) {
                if ( array_key_exists('source', $post['meta']) && is_array($post['meta']['source']) ) {
                    $ico = strtolower(NoNull($post['meta']['source']['network']));
                    if ( $ico == 'App.Net' ) { $ico = 'adn'; }
                    if ( in_array($ico, array('adn', 'twitter')) ) {
                        $SourceIcon = '<i class="fa fa-' . strtolower(NoNull($post['meta']['source']['network'])) . '"></i>';
                    }
                }
            }

            $ReplStr = array( '[POST_GUID]'     => NoNull($post['guid']),
                              '[POST_TYPE]'     => NoNull($post['type']),
                              '[POST_CLASS]'    => $postClass,
                              '[AUTHOR_NAME]'       => NoNull($post['persona']['name']),
                              '[AUTHOR_PERSONA]'    => NoNull($post['persona']['display_name'], $post['persona']['as']),
                              '[AUTHOR_PROFILE]'    => NoNull($post['persona']['profile_url']),
                              '[AUTHOR_AVATAR]'     => NoNull($post['persona']['avatar']),
                              '[AUTHOR_FOLLOW_URL]' => NoNull($post['persona']['follow']['url']),
                              '[AUTHOR_FOLLOW_RSS]' => NoNull($post['persona']['follow']['rss']),
                              '[TITLE]'         => NoNull($post['title']),
                              '[CONTENT]'       => NoNull($post['content']) . NoNull($ReplyHTML),
                              '[BANNER]'        => '',
                              '[TAGLINE]'       => NoNull($tagLine, $this->strings['lblNoTags']),
                              '[HOMEURL]'       => NoNull($this->settings['HomeURL']),
                              '[GEOTAG]'        => $geoLine,
                              '[THREAD]'        => $PostThread,
                              '[SOURCE_NETWORK]'=> NoNull($post['meta']['source']['network']),
                              '[SOURCE_ICON]'   => $SourceIcon,
                              '[PUBLISH_AT]'    => NoNull($post['publish_at']),
                              '[PUBLISH_UNIX]'  => nullInt($post['publish_unix']),
                              '[UPDATED_AT]'    => NoNull($post['updated_at']),
                              '[UPDATED_UNIX]'  => nullInt($post['updated_unix']),
                              '[CANONICAL]'     => NoNull($post['canonical_url']),
                              '[POST_SLUG]'     => NoNull($post['slug']),
                              '[REPLY_TO]'      => NoNull($post['reply_to']),
                              '[POST_STARRED]'  => BoolYN($post['attributes']['starred']),
                              '[CAN_EDIT]'      => BoolYN($post['can_edit']),
                             );

            switch ( $post['type'] ) {
                case 'post.quotation':
                case 'post.bookmark':
                    $ReplStr['[SOURCE_TITLE]'] = NoNull($post['meta']['source']['title'], $post['meta']['source']['url']);
                    $ReplStr['[SOURCE_URL]'] = NoNull($post['meta']['source']['url']);
                    $ReplStr['[SOURCE_DOMAIN]'] = parse_url($post['meta']['source']['url'], PHP_URL_HOST);
                    $ReplStr['[SOURCE_SUMMARY]'] = NoNull($post['meta']['source']['summary']);
                    $ReplStr['[SOURCE_AUTHOR]'] = NoNull($post['meta']['source']['author']);
                    break;

                default:
                    /* Do Nothing */
            }

            // Add the Theme Language Text
            if ( is_array($this->strings) ) {
                foreach ( $this->strings as $key=>$val ) {
                    if ( in_array($key, $ReplStr) === false ) { $ReplStr["[$key]"] = $val; }
                }
            }

            // Determine the Template File
            $flatFile = THEME_DIR . '/' . $data['location'] . '/flats/' . $post['type'] . '.html';
            if ( !file_exists($flatFile) ) { $flatFile = FLATS_DIR . '/templates/' . $post['type'] . '.html'; }

            // Generate the HTML
            $html = readResource($flatFile, $ReplStr);

            // Save the File to Cache if Required
            if ( nullInt(ENABLE_CACHING) == 1 ) { saveCache($data['site_id'], $cache_file, $html); }

            // Return the HTML Element
            return $html;
        }

        // If We're Here, Something Is Wrong
        return '';
    }

    /** ********************************************************************* *
     *  Post-Publish Functions
     ** ********************************************************************* */
    /**
     *  Get Posts that Need to have Post-Publishing Tasks Performed
     */
    private function _setPostPublishData( $PostID = 0 ) {
        if ( $PostID <= 0 ) { return false; }

        $ReplStr = array( '[POST_ID]' => nullInt($PostID) );
        $sqlStr = readResource(SQL_DIR . '/posts/getPostPublishData.sql', $ReplStr);
        $rslt = doSQLQuery($sqlStr);
        if ( is_array($rslt) ) {
            require_once( LIB_DIR . '/webmention.php' );
            $webm = new Webmention( $this->settings, $this->strings );
            $data = $webm->performAction();

            foreach ( $rslt as $Row ) {
                $PostText = $this->_getMarkdownHTML($Row['post_text'], $Row['post_id'], YNBool($Row['is_note']), true);
                $PostUrl = NoNull($Row['post_url']);

                // Send the Webmentions
                $data = $webm->sendMentions($PostUrl, $PostText);
            }
            unset($webm);
        }

        // Return a Happy Boolean
        return true;
    }

    /** ********************************************************************* *
     *  Timeline / Stream Functions
     ** ********************************************************************* */
    private function _processTimeline( $posts ) {
        if ( is_array($posts) ) {
            $default_avatar = $this->settings['HomeURL'] . '/avatars/default.png';
            $data = array();
            $mids = array();

            // Collect the Mentions
            foreach ( $posts as $post ) {
                if ( YNBool($post['has_mentions']) ) {
                    $mids[] = nullInt($post['post_id']);
                }
            }
            if ( count($mids) > 0 ) {
                $pms = $this->_getPostMentions($mids);
            }

            foreach ( $posts as $post ) {
                if ( YNBool($post['is_visible']) ) {
                    // Is there Meta-Data? If So, Grab It
                    $poMeta = false;
                    if ( YNBool($post['has_meta']) ) { $poMeta = $this->_getPostMeta($post['post_guid']); }
                    if ( NoNull($this->settings['nom']) != '' ) {
                        if ( is_array($poMeta) === false ) { $poMeta = array(); }
                        $poMeta['nom'] = NoNull($this->settings['nom']);
                    }

                    // Process any Tags
                    $poTags = false;
                    if ( NoNull($post['post_tags']) != '' ) {
                        $poTags = array();
                        $tgs = explode(',', NoNull($post['post_tags']));
                        foreach ( $tgs as $tag ) {
                            $key = $this->_getSafeTagSlug(NoNull($tag));
                            $poTags[] = array( 'url'  => NoNull($post['site_url']) . '/tag/' . $key,
                                               'name' => NoNull($tag),
                                              );
                        }
                    }

                    // Do We Have Mentions? Grab the List
                    $mentions = false;
                    if ( YNBool($post['has_mentions']) ) {
                        $mentions = $this->_getPostMentions($post['post_id']);
                    }

                    // Prep the Post-Text
                    $IsNote = true;
                    if ( in_array(NoNull($Row['post_type']), array('post.article', 'post.quotation', 'post.bookmark')) ) { $IsNote = false; }
                    $post_text = $this->_getMarkdownHTML($post['value'], $post['post_id'], $IsNote, true);
                    $post_text = $this->_parsePostMentions($post_text);

                    $data[] = array( 'guid'     => NoNull($post['post_guid']),
                                     'type'     => NoNull($post['type']),
                                     'privacy'  => NoNull($post['privacy_type']),

                                     'canonical_url' => NoNull($post['canonical_url']),
                                     'reply_to'      => ((NoNull($post['reply_to']) == '') ? false : NoNull($post['reply_to'])),

                                     'title'    => ((NoNull($post['title']) == '') ? false : NoNull($post['title'])),
                                     'content'  => $post_text,
                                     'text'     => NoNull($post['value']),

                                     'meta'     => $poMeta,
                                     'tags'     => $poTags,
                                     'mentions' => $mentions,

                                     'persona'  => array( 'guid'        => NoNull($post['persona_guid']),
                                                          'as'          => '@' . NoNull($post['persona_name']),
                                                          'name'        => NoNull($post['display_name']),
                                                          'avatar'      => NoNull($post['avatar_url']),
                                                          'you_follow'  => false,
                                                          'is_you'      => YNBool($post['is_you']),

                                                          'profile_url' => NoNull($post['profile_url']),
                                                         ),

                                     'publish_at'   => date("Y-m-d\TH:i:s\Z", strtotime($post['publish_at'])),
                                     'publish_unix' => strtotime($post['publish_at']),
                                     'expires_at'   => ((NoNull($post['expires_at']) == '') ? false : date("Y-m-d\TH:i:s\Z", strtotime($post['expires_at']))),
                                     'expires_unix' => ((NoNull($post['expires_at']) == '') ? false : strtotime($post['expires_at'])),
                                     'updated_at'   => date("Y-m-d\TH:i:s\Z", strtotime($post['updated_at'])),
                                     'updated_unix' => strtotime($post['updated_at']),
                                    );
                }
            }

            // Set the "HasMore" Meta value
            $CleanCount = nullInt($this->settings['count'], 100);
            if ( $CleanCount > 250 ) { $CleanCount = 250; }
            if ( $CleanCount <= 0 ) { $CleanCount = 100; }
            if ( $CleanCount == count($data) ) { $this->settings['has_more'] = true; }

            // Return the Data If We Have Some
            if ( is_array($data) && count($data) > 0 ) { return $data; }
        }

        // If We're Here, There Are No Posts
        return array();
    }

    /**
     *  Function Collects the Global Timeline for the Types that are Requested
     */
    private function _getTimeline( $path = 'global' ) {
        $path = NoNull(strtolower($path), 'global');
        $validTLs = array( 'global', 'mentions' );
        if ( in_array($path, $validTLs) === false ) { $this->_setMetaMessage("Invalid Timeline Path Requested", 400); return false; }

        // Get the Types Requested (Default is Social Posts Only)
        $validTypes = array( 'post.article', 'post.note', 'post.quotation', 'post.bookmark' );
        $CleanTypes = '';
        $rTypes = explode(',', NoNull($this->settings['types'], $this->settings['post_types']));
        if ( is_array($rTypes) ) {
            foreach ( $rTypes as $rType ) {
                $rType = strtolower($rType);
                if ( in_array($rType, $validTypes) ) {
                    if ( $CleanTypes != '' ) { $CleanTypes .= ', '; }
                    $CleanTypes .= "'" . sqlScrub($rType) . "'";
                }
            }
        } else {
            if ( is_string($rTypes) ) {
                $rType = strtolower($rTypes);
                if ( in_array($rType, $validTypes) ) {
                    if ( $CleanTypes != '' ) { $CleanTypes .= ', '; }
                    $CleanTypes .= "'" . sqlScrub($rType) . "'";
                }
            }
        }
        if ( $CleanTypes == '' ) { $CleanTypes = "'post.note'"; }

        // Get the Timerange
        $SinceUnix = nullInt($this->settings['since']);
        $UntilUnix = nullInt($this->settings['until']);

        // How Many Posts?
        $CleanCount = nullInt($this->settings['count'], 100);
        if ( $CleanCount > 250 ) { $CleanCount = 250; }
        if ( $CleanCount <= 0 ) { $CleanCount = 100; }

        // Get the Posts
        $ReplStr = array( '[ACCOUNT_ID]' => nullInt($this->settings['_account_id']),
                          '[SINCE_UNIX]' => nullInt($SinceUnix),
                          '[UNTIL_UNIX]' => nullInt($UntilUnix),
                          '[POST_TYPES]' => NoNull($CleanTypes),
                          '[COUNT]'      => nullInt($CleanCount),
                         );
        $sqlStr = readResource(SQL_DIR . '/posts/getTimeline' . ucfirst($path) . '.sql', $ReplStr);
        if ( $sqlStr != '' ) {
            $rslt = doSQLQuery($sqlStr);
            if ( is_array($rslt) ) {
                return $this->_processTimeline($rslt);

            } else {
                // If there are no results, and the Since/Until is set as 0, expand the criteria
                if ( nullInt($this->settings['since']) <= 0 ) {
                    $this->settings['before'] = 0;
                    $this->settings['since'] = 1;

                    // Run the Query One More Time
                    return $this->_getTimeline($path);
                }
            }
        }

        // If We're Here, No Posts Could Be Retrieved
        return array();
    }

    /** ********************************************************************* *
     *  Markdown Formatting Functions
     ** ********************************************************************* */
    /**
     *  Function Converts a Text String to HTML Via Markdown
     */
    private function _getMarkdownHTML( $text, $post_id, $isNote = false, $showLinkURL = false ) {
        $Excludes = array("\r", "\n", "\t");
        $ValidateUrls = false;
        if ( defined('VALIDATE_URLS') ) { $ValidateUrls = YNBool(VALIDATE_URLS); }

        // Fix the Lines with Breaks Where Appropriate
        $text = str_replace("\r", "\n", $text);
        $lines = explode("\n", $text);
        $inCodeBlock = false;
        $fixed = '';
        $last = '';
        foreach ( $lines as $line ) {
            $thisLine = NoNull($line);
            if ( mb_strpos($thisLine, '```') ) { $inCodeBlock = !$inCodeBlock; }
            if ( $inCodeBlock ) { $thisLine = $line; }
            $doBR = ( $fixed != '' && $last != '' && $thisLine != '' ) ? true : false;

            // If We Have What Looks Like a List, Prep It Accordingly
            if ( nullInt(mb_substr($thisLine, 0, 2)) > 0 && nullInt(mb_substr($last, 0, 2)) > 0 ) { $doBR = false; }
            if ( mb_substr($thisLine, 0, 2) == '* ' && mb_substr($last, 0, 2) == '* ' ) { $doBR = false; }
            if ( mb_substr($thisLine, 0, 2) == '- ' && mb_substr($last, 0, 2) == '- ' ) { $doBR = false; }

            if ( mb_substr($thisLine, 0, 2) == '* ' && mb_substr($last, 0, 2) != '* ' && strlen($last) > 0 ) {
                $fixed .= "\n";
                $doBR = false;
            }
            if ( mb_substr($thisLine, 0, 2) == '- ' && mb_substr($last, 0, 2) != '- ' && strlen($last) > 0 ) {
                $fixed .= "\n";
                $doBR = false;
            }

            if ( nullInt(mb_substr($thisLine, 0, 2)) > 0 && $last == '' ) { $fixed .= "\n"; }
            if ( mb_substr($thisLine, 0, 2) == '* ' && $last == '' ) { $fixed .= "\n"; }
            if ( mb_substr($thisLine, 0, 2) == '- ' && $last == '' ) { $fixed .= "\n"; }

            $fixed .= ( $doBR ) ? '<br>' : "\n";
            $fixed .= $thisLine;
            $last = NoNull($thisLine);
        }
        $text = NoNull($fixed);

		// Construct the Footnotes
		$fnotes = '';
    	if (preg_match_all('/\[(\d+\. .*?)\]/s', $text, $matches)) {
        	$notes = array();
            $n = 1;

    		foreach($matches[0] as $fn) {
    			$note = preg_replace('/\[\d+\. (.*?)\]/s', '\1', $fn);
    			$notes[$n] = $note;

                if ( $isNote ) {
                    $text = str_replace($fn, "<sup>$n</sup>", $text);
                } else {
                    $text = str_replace($fn, "<sup id=\"fnref:$post_id.$n\"><a rel=\"footnote\" href=\"#fn:$post_id.$n\" title=\"\">$n</a></sup>", $text);
                }
    			$n++;
    		}

            $fnotes .= '<hr><ol>';
    		for($i=1; $i<$n; $i++) {
        		if ( $isNote ) {
            		$fnotes .= "<li class=\"footnote\">$notes[$i]</li>";
                } else {
    			    $fnotes .= "<li class=\"footnote\" id=\"fn:$post_id.$i\">$notes[$i] <a rel=\"footnote\" href=\"#fnref:$post_id.$i\" title=\"\">↩</a></li>";
    			}
    		}
    		$fnotes .= '</ol>';
        }
        if ( $fnotes != '' ) { $text .= $fnotes; }

        // Handle Code Blocks
    	if (preg_match_all('/\```(.+?)\```/s', $text, $matches)) {
    		foreach($matches[0] as $fn) {
        		$cbRepl = array( '```' => '', '<code><br>' => "<code>", '<br></code>' => '</code>');
    			$code = "<pre><code>" . str_replace(array_keys($cbRepl), array_values($cbRepl), $fn) . "</code></pre>";
    			$code = str_replace(array_keys($cbRepl), array_values($cbRepl), $code);
    			$text = str_replace($fn, $code, $text);
    		}
        }

        // Handle Strikethroughs
    	if (preg_match_all('/\~~(.+?)\~~/s', $text, $matches)) {
    		foreach($matches[0] as $fn) {
        		$stRepl = array( '~~' => '' );
    			$code = "<del>" . NoNull(str_replace(array_keys($stRepl), array_values($stRepl), $fn)) . "</del>";
    			$text = str_replace($fn, $code, $text);
    		}
        }

        // Get the Markdown Formatted
        $text = str_replace('\\', '&#92;', $text);
        $rVal = Markdown::defaultTransform($text, $isNote);
        for ( $i = 0; $i <= 5; $i++ ) {
            foreach ( $Excludes as $Item ) {
                $rVal = str_replace($Item, '', $rVal);
            }
        }

        // Replace any Hashtags if they exist
        $rVal = str_replace('</p>', '</p> ', $rVal);
        $words = explode(' ', " $rVal ");
        $out_str = '';
        foreach ( $words as $word ) {
            $clean_word = NoNull(strip_tags($word));
            $hash = '';

            if ( NoNull(substr($clean_word, 0, 1)) == '#' ) {
                $hash_scrub = array('#', '?', '.', ',', '!');
                $hash = NoNull(str_replace($hash_scrub, '', $clean_word));

                if ($hash != '' && mb_stripos($hash_list, $hash) === false ) {
                    if ( $hash_list != '' ) { $hash_list .= ','; }
                    $hash_list .= strtolower($hash);
                }
            }
            $out_str .= ($hash != '') ? str_ireplace($clean_word, '<a class="hash" href="[HOMEURL]/tag/' . strtolower($hash) . '" data-hash="' . strtolower($hash) . '">' . NoNull($clean_word) . '</a> ', $word)
                                      : "$word ";
        }
        $rVal = NoNull($out_str);

        // Format the URLs as Required
        $url_pattern = '#(www\.|https?://)?[a-z0-9]+\.[a-z0-9]\S*#i';
        $fixes = array( 'http//'  => "http://",         'http://http://'   => 'http://',
                        'https//' => "https://",        'https://https://' => 'https://',
                        ','       => '',                'http://https://'  => 'https://',
                       );
        $splits = array( '</p><p>' => '</p> <p>', '<br>' => '<br> ' );
        $scrub = array('#', '?', '.', ':', ';');
        $words = explode(' ', ' ' . str_replace(array_keys($splits), array_values($splits), $rVal) . ' ');

        $out_str = '';
        foreach ( $words as $word ) {
            // Do We Have an Unparsed URL?
            if ( mb_strpos($word, '.') !== false && mb_strpos($word, '.') <= (mb_strlen($word) - 1) && NoNull(str_ireplace('.', '', $word)) != '' &&
                 mb_strpos($word, '[') === false && mb_strpos($word, ']') === false ) {
                $clean_word = str_replace("\n", '', strip_tags($word));
                if ( in_array(substr($clean_word, -1), $scrub) ) { $clean_word = substr($clean_word, 0, -1); }

                $url = ((stripos($clean_word, 'http') === false ) ? "http://" : '') . $clean_word;
                $url = str_ireplace(array_keys($fixes), array_values($fixes), $url);
                $headers = false;

                // Ensure We Have a Valid URL Here
                $hdParts = explode('.', $url);
                $hdCount = 0;

                // Count How Many Parts We Have
                if ( is_array($hdParts) ) {
                    foreach( $hdParts as $item ) {
                        if ( NoNull($item) != '' ) { $hdCount++; }
                    }
                }

                // No URL Has Just One Element
                if ( $hdCount > 0 ) {
                    if ( $ValidateUrls ) {
                        if ( $hdCount > 1 ) { $headers = get_headers($url); }

                        if ( is_array($headers) ) {
                            $okHead = array('HTTP/1.0 200 OK', 'HTTP/1.1 200 OK', 'HTTP/2.0 200 OK');
                            $suffix = '';
                            $rURL = $url;

                            // Do We Have a Redirect?
                            foreach ($headers as $Row) {
                                if ( mb_strpos(strtolower($Row), 'location') !== false ) {
                                    $rURL = NoNull(str_ireplace('location:', '', strtolower($Row)));
                                    break;
                                }
                                if ( in_array(NoNull(strtoupper($Row)), $okHead) ) { break; }
                            }

                            $host = parse_url($rURL, PHP_URL_HOST);
                            if ( $host != '' && $showLinkURL ) {
                                if ( mb_strpos(strtolower($clean_word), strtolower($host)) === false ) {
                                    $suffix = " [" . strtolower(str_ireplace('www.', '', $host)) . "]";
                                }
                            }

                            $clean_text = $clean_word;
                            if ( mb_stripos($clean_text, '?') ) {
                                $clean_text = substr($clean_text, 0, mb_stripos($clean_text, '?'));
                            }

                            $word = str_ireplace($clean_word, '<a target="_blank" href="' . $rURL . '">' . $clean_text . '</a>' . $suffix, $word);
                        }

                    } else {
                        $hparts = explode('.', parse_url($url, PHP_URL_HOST));
                        $domain = '';
                        $parts = 0;
                        $nulls = 0;

                        for ( $dd = 0; $dd < count($hparts); $dd++ ) {
                            if ( NoNull($hparts[$dd]) != '' ) {
                                $domain = NoNull($hparts[$dd]);
                                $parts++;
                            } else {
                                $nulls++;
                            }
                        }

                        if ( $nulls == 0 && $parts > 1 && isValidTLD($domain) ) {
                            $host = parse_url($url, PHP_URL_HOST);
                            if ( $host != '' && $showLinkURL ) {
                                if ( mb_strpos(strtolower($clean_word), strtolower($host)) === false ) {
                                    $suffix = " [" . strtolower(str_ireplace('www.', '', $host)) . "]";
                                }
                            }

                            $clean_text = $clean_word;
                            if ( mb_stripos($clean_text, '?') ) {
                                $clean_text = substr($clean_text, 0, mb_stripos($clean_text, '?'));
                            }

                            $word = str_ireplace($clean_word, '<a target="_blank" href="' . $url . '">' . $clean_text . '</a>' . $suffix, $word);
                        }
                    }
                }
            }

            // Output Something Here
            $out_str .= " $word";
        }

        // Fix any Links that Don't Have Targets
        $rVal = str_ireplace('<a href="', '<a target="_blank" href="', $out_str);
        $rVal = str_ireplace('<a target="_blank" href="http://mailto:', '<a href="mailto:', $rVal);

        // Do Not Permit Any Forbidden Characters to Go Back
        $forbid = array( '<script'      => "&lt;script",    '</script'           => "&lt;/script",   '< script'     => "&lt;script",
                         '<br></p>'     => '</p>',          '<br></li>'          => '</li>',         '<br> '        => '<br>',
                         '&#95;'        => '_',             '&amp;#92;'          => '&#92;',         ' </p>'        => '</p>',
                         '&lt;iframe '  => '<iframe ',      '&gt;&lt;/iframe&gt' => '></iframe>',    '&lt;/iframe>' => '</iframe>',
                         '</p></p>'     => '</p>',          '<p><p>'             => '<p>',

                         '<p><blockquote>' => '<blockquote>'
                        );
        for ( $i = 0; $i < 10; $i++ ) {
            $rVal = str_replace(array_keys($forbid), array_values($forbid), $rVal);
        }

        // Return the Markdown-formatted HTML
        return NoNull($rVal);
    }

    /**
     *  Function Reads Through the Post Text and Pulls out Hashtags as Tags
     */
    function _getTagsFromPost( $text, $post_id ) {
        $text = strip_tags($text);
        $words = explode(' ', " $text ");
        $tags = array();
        $lst = '';

        foreach ( $words as $word ) {
            $clean_word = NoNull($word);
            $hash = '';

            if ( NoNull(substr($clean_word, 0, 1)) == '#' ) {
                $hash_scrub = array('#', '?', '.', ',', '!');
                $hash = NoNull(str_replace($hash_scrub, '', $clean_word));

                if ($hash != '' && in_array($hash, $tags) === false ) {
                    $Key = $this->_getSafeTagSlug(NoNull($hash));
                    $Val = sqlScrub($hash);

                    if ( $lst != '' ) { $lst .= ","; }
                    $lst .= "($post_id, '$Key', '$Val')";
                }
            }
        }

        // Return the VALUES list
        return $lst;
    }

    /**
     *  Function Reads Through the Post Text and Pulls out Mentions
     */
    function _getMentionsFromPost( $text, $post_id ) {
        $text = strip_tags($text);
        $words = explode(' ', " $text ");
        $pnames = array();
        $lst = '';

        foreach ( $words as $word ) {
            $invalids = array('//', '</');
            $clean_word = NoNull(str_replace(array_keys($invalids), '', $word));
            $name = '';

            if ( NoNull(substr($clean_word, 0, 1)) == '@' ) {
                $name_scrub = array('@', '#', '?', '.', ',', '!', "\r", "\t", "\n", '//', '</');
                $name = NoNull(str_replace($name_scrub, '', $clean_word));

                if ($name != '' && in_array($name, $pnames) === false ) {
                    $pid = $this->_getPersonaIDFromName($name);
                    if ( $pid !== false && $pid > 0 ) {
                        if ( $lst != '' ) { $lst .= ","; }
                        $lst .= "($post_id, $pid)";
                    }
                }
            }
        }

        // Return the VALUES list
        return $lst;
    }

    function _getPersonaIDFromName( $name ) {
        if ( array_key_exists('personas', $this->settings) === false ) {
            $sqlStr = readResource(SQL_DIR . '/posts/getPersonaList.sql');
            $rslt = doSQLQuery($sqlStr);
            if ( is_array($rslt) ) {
                $this->settings['personas'] = array();

                foreach ( $rslt as $Row ) {
                    $this->settings['personas'][ NoNull($Row['name']) ] = nullInt($Row['id']);
                }
            }
        }

        // If We Have the Persona Name, Return the ID. Send an Unhappy Boolean Otherwise.
        if ( is_array($this->settings['personas']) ) {
            if ( array_key_exists($name, $this->settings['personas']) ) { return $this->settings['personas'][$name]; }
        }
        return false;
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

    /**
     *  Function Converts a Time from the Account's Current Timezone to UTC
     */
    private function _convertTimeToUTC( $DateString ) {
        $offset = nullInt($this->settings['_timezone']) * 3600;
        $dts = strtotime($DateString);

        return date("Y-m-d H:i:s", $dts + $offset);
    }

    /**
     *  Function Converts a Tag Name to a Valid Slug
     */
    private function _getSafeTagSlug( $TagName ) {
        $ReplStr = array( ' ' => '-', '--' => '-' );
        $dash = '-';
        $tag = strtolower(trim(preg_replace('/[\s-]+/', $dash, preg_replace('/[^A-Za-z0-9-]+/', $dash, preg_replace('/[&]/', 'and', preg_replace('/[\']/', '', iconv('UTF-8', 'ASCII//TRANSLIT', NoNull($TagName)))))), $dash));
        for ( $i = 0; $i < 10; $i++ ) {
            $tag = str_replace(array_keys($ReplStr), array_values($ReplStr), $tag);
            if ( mb_substr($tag, 0, 1) == '-' ) { $tag = mb_substr($tag, 1); }
        }
        return NoNull($tag, strtolower(str_replace(array_keys($ReplStr), array_values($ReplStr), $TagName)));
    }

    /**
     *  Function Checks that a Post Slug is Unique and Valid
     */
    private function _checkUniqueSlug( $ChannelGUID, $PostGUID, $PostSlug ) {
        $Excludes = array('feeds', 'images', 'api', 'cdn', 'note', 'article', 'bookmark', 'quotation', 'profile');

        for ( $i = 0; $i <= 49; $i++ ) {
            $TrySlug = $PostSlug;
            if ( $i > 0 ) { $TrySlug = "$PostSlug-$i"; }

            // Check to See If This Slug is Valid or Not
            if ( in_array($TrySlug, $Excludes) === false ) {
                $ReplStr = array( '[CHANNEL_GUID]' => sqlScrub($ChannelGUID),
                                  '[POST_GUID]'    => sqlScrub($PostGUID),
                                  '[POST_SLUG]'    => sqlScrub($TrySlug),
                                 );
                $sqlStr = readResource(SQL_DIR . '/chkUniqueSlug.sql', $ReplStr);
                $rslt = doSQLQuery($sqlStr);
                if ( is_array($rslt) ) {
                    foreach ( $rslt as $Row ) {
                        if ( nullInt($Row) <= 0 ) { return $PostSlug; }
                    }
                }
            }
        }

        // If We're Here, Then Something's Off. Return an Empty String to Force a GUID
        return '';
    }
}
?>