<?php
/*
Plugin Name: Call Tracking Metrics
Plugin URI: http://calltrackingmetrics.com/
Description: Easily manage and track incoming phone calls to your website with Call Tracking Metrics
Author: Andrew Hunter, Jonathan Phillips and Todd Fisher
Version: 0.3.2
Author URI: http://calltrackingmetrics.com/
*/

class CallTrackingMetrics {
  function CallTrackingMetrics() {
    add_action('wp_print_scripts', array(&$this, "call_tracking_metrics_script"), 10);
    add_action('admin_init', array(&$this, 'init_plugin'));
    add_action('admin_menu', array(&$this, 'attach_call_tracking_configuration'));
    $this->ctm_host = "ctmdev.co";
  }

  function init_plugin() {
    // register settings
    register_setting("call-tracking-metrics", "call_track_account_script");
    register_setting("call-tracking-metrics", "ctm_api_key");
    register_setting("call-tracking-metrics", "ctm_api_secret");
    register_setting("call-tracking-metrics", "ctm_api_auth_token");
    register_setting("call-tracking-metrics", "ctm_api_auth_expires");
    register_setting("call-tracking-metrics", "ctm_api_auth_account");
    register_setting("call-tracking-metrics", "ctm_api_connect_failed");
    register_setting("call-tracking-metrics", "ctm_api_stats");
    register_setting("call-tracking-metrics", "ctm_api_stats_expires");
    $this->check_token();
    $this->check_stats();

    add_filter('admin_head', array(&$this, 'add_highcharts'));
  }

  function add_highcharts() {
    global $parent_file;

    if ( $parent_file == 'index.php') {
      echo '"<script src="//cdnjs.cloudflare.com/ajax/libs/highcharts/2.3.5/highcharts.js"></script>';
      //echo '<script src="http://code.highcharts.com/3.0.1/highcharts.js"></script>';
      echo '<script src="//cdnjs.cloudflare.com/ajax/libs/mustache.js/0.7.2/mustache.min.js"></script>';
    }
  }

  function call_tracking_metrics_script() {
    if (!is_admin()) {
      echo get_option('call_track_account_script'); 
    }
  }

  function attach_call_tracking_configuration() {
    add_options_page('CallTrackingMetrics', 'CallTrackingMetrics', 'administrator', __FILE__, array(&$this,'settings_page'));
    add_action('wp_dashboard_setup', array(&$this, 'install_dash_widget'));
  }

  function install_dash_widget() {
    wp_add_dashboard_widget("ctm_dash", "CallTrackingMetrics", array(&$this, 'admin_dashboard_plugin'));
  }

  // show a snapshot of recent call activity and aggregate stats
  function admin_dashboard_plugin() {
    $ctm_api_key = get_option('ctm_api_key'); 
    $ctm_api_secret = get_option('ctm_api_secret'); 
    if (!$ctm_api_secret || !$ctm_api_key) {
      ?>
        <h4>API keys Must be installed first</h4>
        <p>Install API keys under the settings menu</p>
      <?php
      return;
    }
    $stats = get_option('ctm_api_stats');
    $dates = array();
    $end = date('Y-m-d');
    $count = 0;
    for ($count = 0; $count <= 30; ++$count) {
      array_push($dates, date('Y-m-d', strtotime('-' . $count . ' days')));
    }
    ?>
    <div class="ctm-dash"
         data-dates='<?php echo json_encode($dates); ?>'
         data-today="<?php echo date('Y-m-d') ?>"
         data-start="<?php echo date('Y-m-d', strtotime('-30 days')); ?>"
         data-stats='<?php echo json_encode($stats)?>'>
    </div>
    <script id="ctm-dash-template" type="text/x-mustache">
      <div style="height:250px" class="stats"></div>
      <h3 class="ctm-stat total_calls">Total Calls: {{total_calls}}</h3>
      <h3 class="ctm-stat total_unique_calls">Total Callers: {{total_unique_calls}}</h3>
      <h3 class="ctm-stat average_call_length">Average Call Time: {{average_call_length}}</h3>
      <h3 class="ctm-stat top_call_source">Top Call Source: {{top_call_source}}</h3>
    </script>
    <script>
      if(!Array.prototype.indexOf){Array.prototype.indexOf=function(e){"use strict";if(this==null){throw new TypeError}var t=Object(this);var n=t.length>>>0;if(n===0){return-1}var r=0;if(arguments.length>1){r=Number(arguments[1]);if(r!=r){r=0}else if(r!=0&&r!=Infinity&&r!=-Infinity){r=(r>0||-1)*Math.floor(Math.abs(r))}}if(r>=n){return-1}var i=r>=0?r:Math.max(n-Math.abs(r),0);for(;i<n;i++){if(i in t&&t[i]===e){return i}}return-1}}
      jQuery(function($) {
        var dashTemplate = Mustache.compile($("#ctm-dash-template").html());
        var stats = $.parseJSON($("#ctm_dash .ctm-dash").attr("data-stats"));
        var startDate = $("#ctm_dash .ctm-dash").attr("data-start");
        var endDate = $("#ctm_dash .ctm-dash").attr("data-today");
        var categories = $.parseJSON($("#ctm_dash .ctm-dash").attr("data-dates")).reverse();
        $("#ctm_dash .ctm-dash").html(dashTemplate(stats));
        var data = [], calls = stats.stats.calls;
        for (var i = 0, len = categories.length; i < len; ++i) {
          data.push(0);
        }
        for (var c in calls) {
          data[categories.indexOf(c)] = calls[c];
        }
        var series = [{name: 'Calls', data: data}];
        var chart = new Highcharts.Chart({
          credits: { enabled: false },
          chart: { type: 'column', renderTo: $("#ctm_dash .stats").get(0), plotBackgroundColor:null, backgroundColor: 'transparent' },
          yAxis: { min: 0, title: { text: "Calls" } },
          title: { text: 'Last 30 Days' },
          legend: { enabled: false },
          tooltip: { formatter: function() { return '<b>'+ this.x +'</b><br/> '+ this.y; } },
          xAxis: {
            categories: categories,
            labels: { enabled:false }
          },
          series: series
        });
      });
    </script>
    <?php
  }

  function check_stats() {
    $ctm_api_auth_token   = get_option('ctm_api_auth_token');
    if (!$ctm_api_auth_token) { return; }
    $ctm_api_stats_expires = strtotime(get_option('ctm_api_stats_expires'));
    $time = time();
    if (!$ctm_api_stats_expires || $ctm_api_stats_expires < time()) {
      $this->refresh_stats($ctm_api_auth_token);
    }
  }

  function refresh_stats($ctm_api_auth_token) {
    $ctm_api_auth_account   = get_option('ctm_api_auth_account');

     // //cdnjs.cloudflare.com/ajax/libs/highcharts/2.3.5/highcharts.js
 // $ed = date('Y-m-d');
 // $sd = date('Y-m-d', strtotime('-7 days'));

    $stats_url = "http://{$this->ctm_host}/api/v1/accounts/$ctm_api_auth_account/reports.json?auth_token=$ctm_api_auth_token";
    error_log($stats_url);

    $req = new WP_Http;
    $res = $req->request($stats_url, array('method' => 'GET'));
    $stats = $res['body'];
    update_option("ctm_api_stats", json_decode($stats));
    update_option("ctm_api_stats_expires", date('Y-m-d H:i:s', strtotime('+10 minutes')));
  }

  function check_token() {
    $ctm_api_key          = get_option('ctm_api_key'); 
    $ctm_api_secret       = get_option('ctm_api_secret'); 
    $ctm_api_auth_token   = get_option('ctm_api_auth_token');
    $ctm_api_auth_expires = get_option('ctm_api_auth_expires');

    if (!$ctm_api_auth_token && $ctm_api_secret && $ctm_api_key) {
      $this->refresh_token($ctm_api_key, $ctm_api_secret);
    } elseif ($ctm_api_auth_token && $ctm_api_auth_expires && strtotime($ctm_api_auth_expires) < time()) {
      $this->refresh_token($ctm_api_key, $ctm_api_secret);
    }
  }
  function refresh_token($ctm_api_key, $ctm_api_secret) {
    $url = "http://{$this->ctm_host}/api/v1/authentication.json";
    $args = array("token" => $ctm_api_key, "secret" => $ctm_api_secret);
    $req = new WP_Http;//wp_remote_post($url, $args);
    $res = $req->request($url, array('method' => 'POST', 'body' => $args));
    $data = json_decode($res['body']);
    if ($data->success) {
      $ctm_api_auth_token = $data->token;
      $ctm_api_auth_expires = $data->expires;
      $ctm_api_auth_account = $data->first_account->id;

      update_option('ctm_api_auth_token', $ctm_api_auth_token);
      update_option('ctm_api_auth_expires', $ctm_api_auth_expires);
      update_option('ctm_api_auth_account', $ctm_api_auth_account);
      delete_option('ctm_api_connect_failed');
    } else {
      update_option('ctm_api_connect_failed', $res);
      delete_option('ctm_api_auth_token');
      delete_option('ctm_api_auth_expires');
      delete_option('ctm_api_auth_account');
    }
  }

  function settings_page() {
?>
  <style>
    #call-tracking-logo {
      background: transparent url(https://d7p5n6bjn5dvo.cloudfront.net/images/logo2.png) no-repeat;
      width:330px;
      height:65px;
      text-indent:-9999px;
    }
  </style>
	<div class="wrap">
	<h2 id="call-tracking-logo">CallTrackingMetrics</h2>
	<form method="post" action="options.php">
    <?php wp_nonce_field('update-options'); ?>
    <table class="form-table">
      <tr valign="top">
        
        <td>
          <strong><label for="call_track_account_script"><?php _e("Call Tracking Script"); ?></label></strong><br/>
          <textarea style="font-size:12px" cols="58" rows="10" id="call_track_account_script" name="call_track_account_script"><?php echo get_option('call_track_account_script'); ?></textarea>
          <cite>Embed the exact code snipet provided here: <a href="https://calltrackingmetrics.com/embed_code">embed code</a></cite>
        </td>
        <td style="width:100%;vertical-align:middle">
          <p>
            This is the embed script provided by call tracking metrics.
          </p>
        </td>
      </tr>
      <tr valign="top">
        <td>
          <?php if (get_option('ctm_api_auth_token')) { ?>
          <div style="background-color:#8cff8b;border-color:#fc0;padding:12px;text-align:center;font-size:18px;">
            API keys verfied
          </div>
          <?php } ?>
          <strong><label for="ctm_api_key"><?php _e("CTM API Key"); ?></label></strong><br/>
          <input class="regular-text code" type="text" id="ctm_api_key" name="ctm_api_key" value="<?php echo get_option('ctm_api_key'); ?>"/>
          <br/>
          <strong><label for="ctm_api_secret"><?php _e("CTM API Secret"); ?></label></strong><br/>
          <input class="regular-text code" type="password" id="ctm_api_secret" name="ctm_api_secret" value=""/>
            <?php if (get_option('ctm_api_key')) { ?>secret saved<?php } ?>
          <br/>
          <cite>Get your API keys from "settings" -&gt; "account settings"</cite>
        </td>
        <td style="width:100%;vertical-align:middle">
          <p>
            API keys will be used to populate stats in your Wordpress Dashboard
          </p>
          <h4>Don't have an account?  <a href="https://calltrackingmetrics.com/plans">Pricing &amp; Sign up</a> now it only takes a few minutes.</h4>
          <p>Once you have the embed code saved in WP - you can control your tracking sources <a href="https://calltrackingmetrics.com/sources">here</a> and view your reports <a href="https://calltrackingmetrics.com/reports/overview">here</a>.
        </td>
      </tr>

    </table>
 
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="call_track_account_script,ctm_api_key,ctm_api_secret" />
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
  </form>
  </div>
<?php
    // if we have a secret and a api key get an auth token
  }
}

function create_call_tracking_metrics() {
  $call_tracking_metrics = new CallTrackingMetrics();
}

add_action( 'plugins_loaded', "create_call_tracking_metrics");
