<?php
/*
Plugin Name: Push Channels
Plugin URI: http://www.pushchannels.com
Description: Use Push Channels to automatically send your visitors your new website content in real time via email, text message, iPhone/Android, and Desktop.
Version: 1.3.1
Author: kavih
Author URI: http://www.pushchannels.com
License: GPL2
*/
class PushChannelsPlugin
{
    const CUSTOM_META_KEY   = 'push_channels_widgets';
    const OPTION_KEY        = 'push_channels_account_info';
    const WIDGET_OPTION_KEY = 'push_channels_widget_info';
    const NUM_EXCERPT_CHARS = 280; //55 words @ 5 chars / word
    
    public static $curr_id  = '';
    
    public static function sPushChannelsHeadInsert()
    {
        $options = get_option(self::OPTION_KEY);

        if (!empty($options))
        {
            $id = $options['id'];
            ?>
            <script type="text/javascript">
            (function() {
                var UID = '';
                var EMAIL = '';
                var CELLPHONE = '';
                var NOTIFO_PHONE_USERNAME = '';
                var NOTIFO_COMP_USERNAME = '';
                var url = document.location.protocol + '//www.pushchannels.com/init?aid=<?php echo $id?>&uid='+UID+'&email='+EMAIL+'&cellphone='+CELLPHONE+'&notifo_phone_username='+NOTIFO_PHONE_USERNAME+'&notifo_comp_username='+NOTIFO_COMP_USERNAME;
                var loader = function()
                {
                    var head    = document.getElementsByTagName('head')[0];
                    var script  = document.createElement('script');
                    script.type = 'text/javascript';
                    script.src  = url;
                    head.appendChild(script);
                }
                if (window.addEventListener)
                {
                    window.addEventListener('load', loader, false);
                }
                else if (window.attachEvent)
                {
                    window.attachEvent('onload', loader);
                }
              })();
            </script>    
            <?php
        }
    }

    public static function sSaveItem($post_id)
    {
        if (!empty($_POST))
        {
            // verify if this is an auto save routine.
            // If it is our form has not been submitted, so we dont want to do anything
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            {
                return $post_id;
            }
            
            // verify this came from the our screen and with proper authorization,
            // because save_post can be triggered at other times
            if ( !wp_verify_nonce( $_POST['push_channels_widget_list_wpnonce'], 'push_channels_widget_list'))
            {
                return $post_id;
            }
    
            $settings = get_option(self::OPTION_KEY);
    
            if (empty($settings) || !isset($settings['api_auth']) || !isset($settings['api_auth']['ak']) || !isset($settings['api_auth']['ap']))
            {
                return $post_id;
            }
    
            //BUG -- This is commented out because for some reason wordpress (upon save) sets the post status to inherit 
            //and this post has a parent "draft" post. Then another process removes the draft and calls publish... 
            /*if (!in_array(get_post_status($post_id),array('publish', 'future')))
            {
                return $post_id;
            }*/
            
            // OK, we're authenticated: we need to find and save the widget id's
            $widget_ids = $_POST[self::CUSTOM_META_KEY];
            
            if (!empty($widget_ids))
            {
                add_post_meta($post_id, self::CUSTOM_META_KEY, $widget_ids, true) or update_post_meta($post_id, self::CUSTOM_META_KEY, $widget_ids);
            }
            else
            {
                delete_post_meta($post_id, self::CUSTOM_META_KEY);
            }
        }
        
        return $post_id;
    }
    
    public static function sPublishItem($post_id)
    {
        // verify if this is an auto save routine.
        // If it is our form has not been submitted, so we dont want to do anything
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
        {
            return $post_id;
        }
        
        // get our meta data (widget ids)
        $widget_ids = get_post_meta($post_id, self::CUSTOM_META_KEY);
        $widget_ids = (!empty($widget_ids) ? $widget_ids[0] : array());
        
        if ( empty($widget_ids))
        {
            return $post_id;
        }

        $settings = get_option(self::OPTION_KEY);

        if (empty($settings) || !isset($settings['api_auth']) || !isset($settings['api_auth']['ak']) || !isset($settings['api_auth']['ap']))
        {
            return $post_id;
        }

        foreach ($widget_ids as &$id)
        {
            $id = '_pc_wid_'.intval($id);
        }
        
        $post = get_post($post_id);
        $args = array(
            'post_type'   => 'attachment',
            'numberposts' => -1,
            'post_status' => null,
            'post_parent' => $post->ID,
        );

        $image = '';
        
        if (function_exists('has_post_thumbnail') && has_post_thumbnail($post_id) && function_exists('wp_get_attachment_image_src'))
        {
            $image = wp_get_attachment_image_src(get_post_thumbnail_id($post_id), 'single-post-thumbnail');
            $image = trim($image[0]);
        }
        
        if ('' == $image)
        {
            $attachments = get_posts($args);
            
            foreach ($attachments as $attachment)
            {
                if (stripos($attachment->post_mime_type, 'image') !== false)
                {
                    $attach = wp_get_attachment_image_src($attachment->ID, array(200,130));
                    
                    if (!empty($attach) && trim($attach[0]) != '')
                    {
                        $image = trim($attach[0]);
                    }
                    break;
                }
            }
        }
        
        $permalink  = get_permalink($post_id);
        $cats       = (array)get_the_category($post_id);
        $tags       = (array)get_the_tags();
        $user_data  = get_userdata($post->post_author);
        
        if ($user_data) 
        {
            $author = $user_data->display_name;
        }
        else
        {
            $author = '';
        }
        
        $content = html_entity_decode($post->post_content);
        
        //Last attempt to get the thumbnail, via the body of the content, first image.
        if ('' == $image)
        {
            preg_match("/<img[\s\S]+?src=(?:\"|')(http[\s\S]+?)(?:\"|')[\s\S]*?>/im", $content, $first_image);
            
            if ($first_image && count($first_image) > 1)
            {
                $image = trim($first_image[1]);
            }
        }
        
        $body = trim(html_entity_decode(str_ireplace('&nbsp;', ' ', $post->post_excerpt)));
        
        if ('' == $body)
        {
            $body = strip_tags(preg_replace("/<br[^>]*?>/im", " ", str_replace("&nbsp;", " ", $content)));
            $body = preg_replace("/^[ \t]+$/im", " ", @iconv('', "UTF-8//IGNORE//TRANSLIT", $body));
            $body = preg_replace("/\r\n?/im", " ", $body);
            $body = preg_replace("/[\f\n]{2,}/im", " ", $body);
            $body = preg_replace("/[\s]{2,}/im", " ", $body);
        }
        
        if (strlen($body) > self::NUM_EXCERPT_CHARS)
        {
            $body = substr($body, 0, self::NUM_EXCERPT_CHARS).'...';
        }
        else
        {
            $body .= '...';
        }
        
        $url_parts  = parse_url($permalink);
        $title      = utf8_encode($post->post_title);
        $body       = utf8_encode($body);
        
        $data = array(
            '_pc_wiid' => $post_id, 
            '_pc_wiurl' => $permalink, 
            '_pc_email_wititle' => $title, 
            '_pc_email_wibody' => $body, 
            '_pc_notifo_wimessage' => $body, 
            '_pc_notifo_winotification_title' => $title, 
            '_pc_notifo_winotification_msg' => $body,
            '_pc_sms_wimessage' => $body,
            '_pc_wids' => $widget_ids 
        );
        
        if (!empty($cats))
        {
            $data['_pc_widigest_url'] = $url_parts['scheme'].'://'.$url_parts['host'].$url_parts['path'].'?cat='.$cats[0]->cat_ID;
            $data['_pc_wicgs'] = array('Categories' => array(), 'Authors' => array(), 'Tags' => array());
            
            foreach ($cats as $cat)
            {
                $data['_pc_wicgs']['Categories'][] = $cat->cat_name;
            }
            
            foreach ($tags as $tag)
            {
                $data['_pc_wicgs']['Tags'][] = $tag->name;
            }
            
            if ($author != '')
            {
                $data['_pc_wicgs']['Authors'][] = $author;
            }
        }
        else
        {
            $data['_pc_widigest_url'] = $url_parts['scheme'].'://'.$url_parts['host'];
        }
        
        if ($image != '')
        {
            $data['_pc_wiimg_src'] = $image;
        }
        
        self::_sDoPush($data, $settings['api_auth']);
        
        return $post_id;
    }

    public static function sPushChannelsAdminSetup()
    {
        add_submenu_page('plugins.php', __('Push Channels', 'push_channels'), __('Push Channels', 'push_channels'), 'manage_options', 'push_channels', array('PushChannelsPlugin', 'sPushChannelsAdminPage'));
        
        add_meta_box('push_channels_widget_list', __('Push Channels Widgets', 'push_channels'), array('PushChannelsPlugin', 'sPushChannelsMetaBoxForm'), 'post', 'side', 'low');
    }

    public static function sPushChannelsAdminPage()
    {
        if (!current_user_can('manage_options'))
        {
            wp_die( __('You do not have sufficient permissions to access this page.') );
        }
        
    	if(isset($_POST) && !empty($_POST)) 
    	{
            if (wp_verify_nonce($_POST['push_channels_update_wpnonce'], 'push_channels_options')) 
            {
                $email      = trim($_POST['pc_email']);
                $password   = trim($_POST['pc_password']);
                
                if ($email != '' && $password != '')
                {
                    $response = wp_remote_post('http://www.pushchannels.com/bp/proxy.php', array(
                        'sslverify' => false,
                        'body' => array('e' => $email, 'p' => $password)
                    ));
                    
                    if(is_wp_error($response)) 
                    {
                    ?>
                    <div class="wrap">
                        <h2>Synchronize WordPress with Push Channels</h2>
                        <div class="tool-box">
                        <font color="Red">An error occurred when synchronizing with your Push Channels account. First, please make sure you have an internet connection and try again. If this error continues to occur, please contact us and copy and paste the following error message in the body of your support inquiry: <b style="color:red;"><?php echo $response->get_error_message()?></b></font>
                        </div>
                    </div>
                    <?php
                    }
                    else 
                    {
                        $data = (array)json_decode($response['body'], true);

                        if (!empty($data) && !empty($data['api_auth']))
                        {
                            $ak = trim($data['api_auth']['ak']);
                            $ap = trim($data['api_auth']['ap']);
                        }
                        
                        switch (true)
                        {
                            case empty($data):
                                ?>
                                <div class="wrap">
                                    <h2>Synchronize WordPress with Push Channels</h2>
                                    <div class="tool-box">
                                    <font color="Red">We could not find your Push Channels account. Please check your login credentials and try again.</font>
                                    </div>
                                </div>
                                <?php
                                break;
                                
                            case 0 == count($data['widgets']):
                                ?>
                                <div class="wrap">
                                    <h2>Synchronize WordPress with Push Channels</h2>
                                    <div class="tool-box">
                                    <font color="Red">We could not synchronize your widgets for ONE of following reasons:</font>
                                    <ul style="list-style:disc;margin:15px;">
                                        <li>Either you do not have any active widgets in your account</li>
                                        <li>Or you did not answer the "How do you want your content pushed for this widget?" question correctly while building your widgets. When answering that question, make sure you pick the <b>"I will use the Push Channels API to push content"</b> option if you want messages sent automatically. If, however, you wish to create widgets to simply collect subscribers and then send them messages manually, choose the <b>"I will manually push out content as needed"</b> option. You can not edit that setting in your widget, so you will need to create a new widget.</li>
                                    </ul>
                                    </div>
                                </div>
                                <?php
                                break;
                                
                            case ('' == $ak || '' == $ap):
                                ?>
                                <div class="wrap">
                                    <h2>Synchronize WordPress with Push Channels</h2>
                                    <div class="tool-box">
                                    <font color="Red">Your Push Channels API authentication is invalid. Please <a href="mailto:support@pushchannels.com">contact us</a> for support.</font>
                                    </div>
                                </div>
                                <?php
                                break;
                                
                            default:
                        		update_option(self::OPTION_KEY, $data);
                                ?>
                                <div class="wrap">
                                    <h2>Synchronize WordPress with Push Channels</h2>
                                    <div class="tool-box">
                                    <font color="Green">Synchronization successful!</font>
                                    <p>
                                    Now click "Back" in your browser and follow <b>Step #3</b>
                                    </p>
                                    </div>
                                </div>
                                <?php
                                break;
                        }
                    }
                }
            }
        }
        else
        {
    	?>
    	<style>
    	.push_channels_fs
    	{
    	   border:1px solid gray;
    	   margin:8px;
    	   padding:10px;
    	}
    	</style>
        <form method="post">
            <?php wp_nonce_field("push_channels_options", 'push_channels_update_wpnonce' ); ?>
            <input type="hidden" name="form_action" value="update_options" />
            <div class="wrap">
                <h2>Push Channels WordPress Plugin</h2>
                <div class="tool-box">
                    <fieldset class="push_channels_fs">
                        <legend><h3 class="title">Step 1: Create/Access your Push Channels account and build your widgets</h3></legend>
                        <b style="color:red;">Important Notes:</b>
                        <div style="float: right; border: 1px solid silver; padding: 5px; color: gray; margin: -12px 0pt 5px 5px;">
                            &raquo; Watch the <a target="_blank" href="http://www.pushchannels.com/?auto_play">Overview Video</a><br>
                            &raquo; Push Channels <a target="_blank" href="http://www.pushchannels.com/">Homepage</a>
                        </div> 
                        <ol>
                        <li>Make sure you pick the <b>"I will use the Push Channels API to push content"</b> option while building your widgets if they will push your content upon saving your Posts. If, however, you wish to create widgets to simply collect subscribers and then send them messages manually, choose the <b>"I will manually push out content as needed"</b> option.</li>
                        <li><div style="width:488px;"><img border="0" src="http://www.pushchannels.com/bp/content_groups.jpg" style="float:right;margin:0 0 8px 8px">When building widgets that will allow subscribers to filter by Categories, Tags, and/or Authors, make sure to set your Content Group Name as "Categories", "Tags", and/or "Authors" and spell your Content Group Values exactly the same as your WordPress Categories, Tags, and/or Authors. Any changes you make to your Wordpress Categories, Tags, and/or Authors will need to be manually updated in your widget Content Group Values. A re-sync (Step #2 below) is required if you make any changes to Content Group Names and/or Values.</div></li>
                        </ol>
                        <div style="clear:both;border:1px solid black;">
                            <iframe frameborder="0" src="http://www.pushchannels.com/app" style="width:100%;height:400px;"></iframe>
                        </div>
                    </fieldset>
                    
                    <fieldset class="push_channels_fs">
                        <legend><h3 class="title">Step 2: Copy & paste your Push Channels account email & password and click synchronize</h3></legend>
                        <div style="font-style:italic;margin-left:20px;margin-bottom:5px;" >NOTE: Every time you make changes or additions to your Push Channels widgets (from Step #1 above), a Synchronization will be required.</div><br clear="all"/>
                        <div style="float:left;text-align:right; width:100px;">
                            <div style="height:25px;">Email:&nbsp;</div>
                            <div style="height:25px;">Password:&nbsp;</div>
                        </div>
                        <div style="float:left;">
                            <input type="text" name="pc_email" value="<?php echo $pc_email; ?>"/><br/>
                            <input type="password" name="pc_password" value="<?php echo $pc_password; ?>"/>
                        </div>
                        <br clear="all"/>
                        <input style="margin-left:20px;margin-top:5px;" type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Synchronize with Push Channels') ?>" />
                    </fieldset>
                    
                    <fieldset class="push_channels_fs">
                        <legend><h3 class="title">Step 3: Place your widgets on your site</h3></legend>
                        <div>Choose how you would like to place your widgets on your website.</div><br clear="all"/>
                        <div style="float:left;width:412px;">
                            <fieldset class="push_channels_fs">
                            <legend><b>Drag and Drop to your sidebar(s)</b></legend>
                            <ol>
                                <li>
                                    Click "Widgets" under "Appearance" in your Wordpress account.<br/>
                                    <img src="http://www.pushchannels.com/bp/click_widgets.jpg" border="0"/>
                                </li>
                                <li>
                                    Drag and Drop the Push Channels Wordpress Widgets to your sidebars.<br/>
                                    <img src="http://www.pushchannels.com/bp/drag_n_drop.jpg" border="0"/>
                                </li>
                            </ol>
                            </fieldset>
                        </div>
                        <span style="font-size: 30pt; float: left; width: 63px; height: 66px; text-align: center; margin-top: 36px;">OR</span>
                        <div style="float:left;width:296px;">
                            <fieldset class="push_channels_fs">
                            <legend><b>Place anywhere</b></legend>
                            Add an HTML element with an "id" equal to the intended widget's ID.<br>
                            Example: &lt;div id="_pc_wid_27"&gt;&lt;/div&gt;
                            </fieldset>
                        </div>
                    </fieldset>
                    
                    <fieldset class="push_channels_fs">
                        <legend><h3 class="title">Your Finished!</h3></legend>
                        While writing your Posts, simply choose which Push Channels widgets your Posts are intended for (see example image below), in the same way you choose Categories for your Posts. Once you submit your Post, it will be sent to all of your Push Channels subscribers that signed up for the widgets you select here.<br/>
                        <img src="http://www.pushchannels.com/bp/choose_widgets.png" border="0"/>
                    </fieldset>
                    

                    <fieldset class="push_channels_fs">
                        <legend><h3 class="title">Are you stuck or have feedback?</h3></legend>
                        <img src="http://www.browsernotincluded.com/pm/misc/me_about.jpg" style="border:1px solid black;float:left;margin:0px 5px 5px 0px;"/>If you are stuck or don't understand any of the steps above, PLEASE email me.<br/>
                        I will respond really quickly, I swear! <a href="mailto:kavih@pushchannels.com">kavih@pushchannels.com</a>
                    </fieldset>
                    
                </div>
            </div>
        </form>
    	<?php
        }
    }
    
    public static function sPushChannelsMetaBoxForm($post)
    {
        $options = get_option(self::OPTION_KEY);

        if (!empty($options) && !empty($options['widgets']))
        {
            // Use nonce for verification
            wp_nonce_field("push_channels_widget_list", 'push_channels_widget_list_wpnonce' );
    
            if (!empty($_REQUEST) && !empty($_REQUEST['post']))
            {
                $post_id = intval($_REQUEST['post']);
                
                //If the post request var is no longer the post id, then let's try to get it from the first arg (which should be the post object
                if (0 == $post_id && is_object($post) && property_exists($post, 'ID'))
                {
                    $post_id = intval($post->ID);
                }
                
                if ($post_id > 0)
                {
                    $widget_ids = get_post_meta($post_id, self::CUSTOM_META_KEY);
                    $widget_ids = (!empty($widget_ids) ? $widget_ids[0] : array());
                }
            }
            
            ?>
            <ul>
			<?php
            foreach ($options['widgets'] as $widget)
            {
            ?>
                <li><label><input type="checkbox" name="<?php echo self::CUSTOM_META_KEY?>[]" value="<?php echo $widget['id']?>" <?php echo ((!empty($widget_ids) && in_array($widget['id'], $widget_ids)) ? 'checked' : '')?>/>&nbsp;<?php echo $widget['name']?></label></li>
            <?php
            }
            ?>
			</ul>
			<?php
        }
    }
    
    public static function sPushChannelsWidgetOutput($args)
    {
        $id             = str_ireplace(self::WIDGET_OPTION_KEY, '', $args['widget_id']);
        $saved_options  = get_option($args['widget_id']);
        
        echo $args['before_widget'];
        
        if (trim($saved_options['title']) != '')
        {
            echo $args['before_title'].$saved_options['title'].$args['after_title'];
        }
        
        if (trim($saved_options['descr']) != '')
        {
            echo '<h2 style="font-size:90%; margin: 3px 0; padding: 3px 0;" _pc_wdescr="_pc_wid_'.$id.'">'.$saved_options['descr'].'</h2>';
        }
        
        echo '<div id="_pc_wid_'.$id.'" style="display:inline-block;"></div>';
        echo $args['after_widget'];
    }
    
    public static function sPushChannelsWidgetReg()
    {
        $options = get_option(self::OPTION_KEY);

        if (!empty($options) && !empty($options['widgets']))
        {
            foreach ($options['widgets'] as $widget)
            {
                wp_register_sidebar_widget(
                    self::WIDGET_OPTION_KEY.$widget['id'],        // your unique widget id
                    'Push Channels Widget: '.$widget['name'], // widget name
                    array('PushChannelsPlugin', 'sPushChannelsWidgetOutput'),  // callback function
                    array(                  // options
                        'description' => 'This is a Push Channels widget and it should be placed in the sidebar of your site.'
                    )
                );
                
                self::$curr_id = self::WIDGET_OPTION_KEY.$widget['id'];
                wp_register_widget_control(self::WIDGET_OPTION_KEY.$widget['id'], 'Push Channels Widget: '.$widget['name'], array('PushChannelsPlugin', 'sPushChannelsWidgetControls'));
            }
        }
    }
    
    public static function sPushChannelsWidgetControls()
    {
        $id             = str_ireplace(self::WIDGET_OPTION_KEY, '', self::$curr_id);
        $post           = false;
        $sent_options   = array();
        
        //if new options were just submitted, save them
        if(isset($_POST['pushchannels_widget_title'.$id]))
        {
            $sent_options['title'] = $_POST['pushchannels_widget_title'.$id];
            $post = true;
        }

        //if new options were just submitted, save them
        if(isset($_POST['pushchannels_widget_descr'.$id]))
        {
            $sent_options['descr'] = $_POST['pushchannels_widget_descr'.$id];
            $post = true;
        }

        if ($post)
        {
            update_option(self::$curr_id, $sent_options);
            echo '<div align="center" style="background-color: #35AD69;border: 2px solid green;color: white;font-weight: bold;margin: 2px;padding: 2px;">Settings saved.</div>';
        }        
        
        //get saved options, if they exist
        $saved_options = get_option(self::$curr_id);
        
        echo '<label for="pushchannels_widget_title'.$id.'">Enter the title to display:<br/><i>(example: "Stop coming to our site!")</i></label><br/>';
        echo '<input type="text" name="pushchannels_widget_title'.$id.'" value="'.$saved_options['title'].'" style="width:225px"/><br/><br/>';
        echo '<label for="pushchannels_widget_descr'.$id.'">Describe what this service does for your visitors:<br/><i>(example: "Why should you keep checking if we have something new? Use the service below to be alerted of our new info!")</i></label><br/>';
        echo '<textarea  name="pushchannels_widget_descr'.$id.'" style="width:225px;height:100px;">'.$saved_options['descr'].'</textarea>';
    }
    
    public static function _sDoPush($data, $auth, $debug = false)
    {
        if (function_exists('curl_init') && !empty($data) && !empty($auth))
        {
            // Create a curl handle and set some basic options
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POST, true);
            
            $curl_data = array();
            
            //Setup our API Key and API Password to send
            $curl_data['ak'] = $auth['ak'];
            $curl_data['ap'] = $auth['ap'];
            
            //Set debug to true to make sure we don't get any errors or warnings for this push.
            //This should be commented out or set to zero (0) for real pushes.
            $curl_data['debug'] = $debug;
            $curl_data['items'][] = $data;
            
            //Now that we have our parameters setup, let's convert them into POST-safe parameters for cURL...
            $o = '';
            foreach ($curl_data as $k=>$v)
            {
                if (!is_scalar($v))
                {
                    $v = json_encode($v);
                }
                $o.= "$k=".utf8_encode(urlencode($v))."&";
            }
            
            $curl_data = substr($o, 0, -1);
            
            //Set our curl data to POST, along with the API endpoint URL
            curl_setopt($ch, CURLOPT_POSTFIELDS, $curl_data);
            curl_setopt($ch, CURLOPT_URL, 'http://www.pushchannels.com/app/api');
            
            //Execute the API call and place the response in $data
            $data = curl_exec($ch);
            
            //...Some kind of error handling should go here...
            /*echo '<pre>';
            print_r(json_decode($data, true)); //<-- JSON decoded response
            echo '</pre>';
            die('');*/
            
            // Close handle
            curl_close($ch);
        }
    }
}

add_action('wp_head', array('PushChannelsPlugin', 'sPushChannelsHeadInsert'));
add_action('admin_menu', array('PushChannelsPlugin', 'sPushChannelsAdminSetup'));
add_action('publish_post', array('PushChannelsPlugin', 'sPublishItem'));
add_action('save_post', array('PushChannelsPlugin', 'sSaveItem'));
add_action("plugins_loaded", array('PushChannelsPlugin', 'sPushChannelsWidgetReg'));
?>