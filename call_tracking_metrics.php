<?php
/*
Plugin Name: Call Tracking Metrics
Plugin URI: http://calltrackingmetrics.com/
Description: Easily manage and track incoming phone calls to your website with Call Tracking Metrics
Author: Andrew Hunter, Jonathan Phillips and Todd Fisher
Version: 0.1
Author URI: http://calltrackingmetrics.com/
*/

class CallTrackingMetrics {
  function CallTrackingMetrics() {
		add_action('wp_print_scripts', array(&$this, "call_tracking_metrics_script"), 10);
    add_action('admin_init', array(&$this, 'init_plugin'));
    add_action('admin_menu', array(&$this, 'attach_call_tracking_configuration'));
  }

  function init_plugin() {
    // register settings
    register_setting("call-tracking-metrics", "call_track_account_script");
  }

  function call_tracking_metrics_script() {
    echo get_option('call_track_account_script'); 
  }

  function attach_call_tracking_configuration() {
    add_options_page('Call Tracking Metrics', 'Call Tracking Metrics', 'administrator', __FILE__, array(&$this,'settings_page'));
  }

  function settings_page() {
?>
  <style>
    #call-tracking-logo {
      background: transparent url(<?php echo plugins_url("logo.png", __FILE__) ?>) no-repeat;
      width:330px;
      height:65px;
      text-indent:-9999px;
    }
  </style>
	<div class="wrap">
	<h2 id="call-tracking-logo">Call Tracking Metrics</h2>
	<form method="post" action="options.php">
    <?php wp_nonce_field('update-options'); ?>
    <table class="form-table">
      <tr valign="top">
        
        <td>
          <strong><label for="call_track_account_script"><?php _e("Call Tracking Script"); ?></label></strong><br/>
          <textarea style="font-size:12px" cols="58" rows="10" id="call_track_account_script" name="call_track_account_script"><?php echo get_option('call_track_account_script'); ?></textarea>
          <cite>Embed the exact code snipet provided here: <a href="http://calltrackingmetrics.com/embed_code">embed code</a></cite>
        </td>
        <td style="width:100%;vertical-align:middle">
          <p>
            This is the embed script provided by call tracking metrics.
          </p>
          <h4>Don't have an account?  <a href="https://calltrackingmetrics.com/users/sign_up">Sign up</a> now it only takes a few minutes.</h4>
          <p>Once you have the embed code saved in WP - you can control your tracking sources <a href="http://calltrackingmetrics.com/physical_phone_numbers">here</a> and view your reports <a href="http://calltrackingmetrics.com/reports/overview">here</a>.
        </td>
      </tr>

      </table>
 
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="call_track_account_script" />
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
  </form>
  </div>
<?php
  }
}

function create_call_tracking_metrics() {
  $call_tracking_metrics = new CallTrackingMetrics();
}

add_action( 'plugins_loaded', "create_call_tracking_metrics");
