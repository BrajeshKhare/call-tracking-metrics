<?php
/*
Plugin Name: CallTrackingMetrics
Plugin URI: https://calltrackingmetrics.com/
Description: Easily manage and track phone calls to your website with CallTrackingMetrics - Call Tracking Plugin for WordPress
Author: Todd Fisher, Bob Graw
Version: 0.4.3
Author URI: https://calltrackingmetrics.com/
*/

class CallTrackingMetrics {
  function CallTrackingMetrics() {
    add_action('wp_print_scripts', array(&$this, "call_tracking_metrics_script"), 10);
    add_action('admin_init', array(&$this, 'init_plugin'));
    add_action('init', array(&$this, 'form_init'));
    add_action('admin_menu', array(&$this, 'attach_call_tracking_configuration'));
    if (isset($_ENV["CTM_BLOG_TEST_URL"])) {
      $this->ctm_host = $_ENV["CTM_BLOG_TEST_URL"];
      $this->request_options = array('sslverify' => false); # , 'sslcertificates' => false);
    } else {
      $this->ctm_host = "https://api.calltrackingmetrics.com";
      $this->request_options = array();
    }
  }

  // quick link in the plugin folder
  function settings_link($links, $file) {
    $plugin = plugin_basename(__FILE__);
    if ( $file != $plugin) { return $links; }
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=ctm-plugin/call_tracking_metrics.php' ) . '">'  . esc_html( __( 'Settings', 'call-tracking-metrics' ) ) . '</a>';
    array_unshift($links, $settings_link); 
    return $links; 
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

    add_filter('admin_head', array(&$this, 'add_javascripts'));
    add_filter('plugin_action_links', array(&$this, 'settings_link'), 10, 2 );

    if ($this->is_api_enabled()) { // hook into contact form 7
      add_filter('wpcf7_add_meta_boxes', array(&$this, 'add_contact_form7_options_panels'));
      add_filter('wpcf7_save_contact_form', array(&$this, 'hook_wpcf7_save_contact_form'));
      add_filter('wpcf7_contact_form_properties', array(&$this, 'hook_wpcf7_contact_form_properties'));
    }
  }

  function form_init() {
    if ($this->is_api_enabled()) { // hook into contact form 7
      add_action("wpcf7_before_send_mail", array(&$this, 'hook_wpcf7_trigger_form_post'), 1, 1);
      add_filter("wpcf7_form_elements", array(&$this, 'hook_wpcf7_form_element_visitor_inject'), 10, 1);
    }
  }

  function is_api_enabled() {
    $ctm_api_key    = get_option('ctm_api_key'); 
    $ctm_api_secret = get_option('ctm_api_secret'); 
    return ($ctm_api_secret && $ctm_api_key);
  }

  function hook_wpcf7_form_element_visitor_inject($elements) {
    $digits = get_option("ctm_form_number");
    # inject script to add visitor_sid to the form has a hidden input field
    $script = "(window.__ctm_loaded = (window.__ctm_loaded || [])).push(function() { var trackingNumber = \"$digits\";" .
              "  var submit = jQuery(jQuery('.wpcf7-form-control.wpcf7-submit')[0]); " .
              "  submit.after('<input type=\"hidden\" name=\"visitor_sid\" value=\"' + __ctm.config.sid + '\"/>');" .
              "  submit.closest('form').submit(function(e) { try {window.ptTrackEvent(\"\", trackingNumber, \"form\"); __ctm.tracker.popQueue(); } catch(e) { console.log(e); } }); " .
              "  submit.click(function(e) { try {window.ptTrackEvent(\"\", trackingNumber, \"form\"); __ctm.tracker.popQueue(); } catch(e) { console.log(e); } }); " .
              "})";
    return $elements . "<script>$script</script>";
  }


  // send the FormReactor 
  function hook_wpcf7_trigger_form_post($contact_form) {

    $field_map   = get_option("ctm_form_{$contact_form->id}");
    $submission  = WPCF7_Submission::get_instance();
    $properties  = $contact_form->get_properties();
    $data        = $submission->get_posted_data();

    $email_field = $field_map["email_field"];
    $name_field  = $field_map["name_field"];
    $phone_field = $field_map["phone_field"];
    $formreactor = $field_map["formreactor"];

    $form_data = array();
    if (isset($data[$name_field])) {
      $form_data['caller_name']   = $data[$name_field];
    }

    $form_data['country_code']  = '1';
    $form_data['phone_number']  = $data[$phone_field];

    if (isset($data['visitor_sid'])) {
      $form_data['visitor_sid']   = $data['visitor_sid']; 
    }

    if (isset($data[$email])) {
      $form_data['email'] = $data[$email];
    }

    foreach($data as $key => $value) {
      if (!preg_match("/^_wpcf7|^_wpnonce|visitor_sid/", $key) && $key != $email_field && $key != $name_field && $key != $phone_field && $key != $formreactor) {
        $form_data["custom_$key"] = $value;
      }
    }

    $this->post_form($formreactor, $form_data);
  }

  function post_form($formreactor, $post_data) {
#   {"visitor_sid"=>"5531086600048b6f1453145a",
#    "form_reactor"=>{"caller_name"=>"foo bar",
#                     "country_code"=>"1",
#                     "phone_number"=>"dddddddddd",
#                     "email"=>"aaaa@example.com"},
#    "field"=>{"custom"=>"foo bar"}}
    $form_url       = "{$this->ctm_host}/api/v1/formreactor/$formreactor";
    $req            = new WP_Http;
    $ctm_api_key    = get_option('ctm_api_key'); 
    $ctm_api_secret = get_option('ctm_api_secret'); 
    $headers        = array('Authorization' => 'Basic ' . base64_encode( "$ctm_api_key:$ctm_api_secret"));
    $post_request   = array_merge($this->request_options,
                                  array('method' => 'POST',
                                        'headers' => $headers,
                                        'user-agent' => "Wordpress Plugin",
                                        'body' => $post_data,
                                        'blocking' => false));
    $res            = $req->request($form_url, $post_request);
  }

  function hook_wpcf7_contact_form_properties($properties) {
    if (!isset($properties["ctm_formreactor"])) {
      $properties["ctm_formreactor"] = array();
    }
    return $properties;
  }

  function hook_wpcf7_save_contact_form($contact_form) {
    $ctm_caller_number_field = $contact_form->id . "-caller_number";
    $ctm_caller_name_field   = $contact_form->id . "-name_field";
    $ctm_caller_email_field  = $contact_form->id . "-email_field";
    $ctm_formreactor_field   = $contact_form->id . "-formreactor";
    $email_field             = $_POST[$ctm_caller_email_field];
    $name_field              = $_POST[$ctm_caller_name_field];
    $phone_field             = $_POST[$ctm_caller_number_field];
    $formreactor             = $_POST[$ctm_formreactor_field];
    $properties              = $contact_form->get_properties();

    $properties["ctm_formreactor"]["email_field"] = $email_field;
    $properties["ctm_formreactor"]["name_field"]  = $name_field;
    $properties["ctm_formreactor"]["phone_field"] = $phone_field;
    $properties["ctm_formreactor"]["formreactor"] = $formreactor;

    $data_to_save = array("phone_field" => $phone_field,
                          "name_field"  => $name_field,
                          "email_field" => $email_field,
                          "formreactor" => $formreactor);


    update_option("ctm_form_{$contact_form->id}", $data_to_save);
    $contact_form->set_properties($properties);

    $this->update_formreactor_data($formreactor);
  }

  # fetch data about the FormReactor and keep it local to show in the form
  function update_formreactor_data($formreactor) {
    $ctm_api_auth_account = get_option('ctm_api_auth_account');
    $ctm_api_key    = get_option('ctm_api_key'); 
    $ctm_api_secret = get_option('ctm_api_secret'); 
    $headers        = array('Authorization' => 'Basic ' . base64_encode( "$ctm_api_key:$ctm_api_secret"));
    $form_url = "{$this->ctm_host}/api/v1/form_reactor/$formreactor.json";
    $req      = new WP_Http;
    $res      = $req->request($form_url,  array_merge($this->request_options, array('method' => 'GET', 'headers' => $headers)));
      
    # need this to track against the visitor data
    if (isset($res) && is_array($res)) {
      $data = $res['body'];
      if (isset($data)) {
        $form               = json_decode($data, true);
        $tracking_number_id = $form['tracking_number']['id'];
        $number_url         = "{$this->ctm_host}/api/v1/accounts/$ctm_api_auth_account/numbers/$tracking_number_id.json";
        $req                = new WP_Http;
        $res                = $req->request($number_url,  array_merge($this->request_options, array('method' => 'GET', 'headers' => $headers)));
        if (isset($res) && is_array($res)) {
          $data = $res['body'];
          if (isset($data)) {
            $number  = json_decode($data, true);
            $split   = $number['split'];
            $country = array_shift($split);
            $digits  = implode($split);
            # we have no way to get to the contact form 7 form on the output so this is global...
            update_option("ctm_form_number", $digits);
            update_option("ctm_form_country_prefix", $country);
          }
        }
      }
    }
  }

  function hook_into_contact_form7_actions() {
    $action = wpcf7_current_action();

    if ( 'save' == $action ) {
    }
    if ( 'copy' == $action ) {
    }
    if ( 'delete' == $action ) {
    }
  }

  function add_contact_form7_options_panels($post_id) {
    add_meta_box( "ctm_form_reactor", "CallTrackingMetrics FormReactor Settings", array(&$this, "show_contact_form7_panel"), null, "form", "low");
  }

  function show_contact_form7_panel($post) { 
    $id = $post->id;
    $formreactor = $post->ctm_formreactor;
    $ctm_api_auth_token = $this->check_token();
?>
    <div class="ctm-fields">
<?php if ($ctm_api_auth_token) { ?>
      <div class="half-left">
        <div class="ctm-field">
          <label for="<?php echo $id; ?>-name_field"><?php echo esc_html( __( 'Contact Name:', 'contact-form-7' ) ); ?></label><br />
          <input placeholder="your-name" type="text" id="<?php echo $id; ?>-name_field" name="<?php echo $id; ?>-name_field" class="wide" size="32" value="<?php echo esc_attr( $formreactor['name_field'] ); ?>" />
          <cite>e.g. the field name used to capture the leads name as it will appear in the call log</cite>
        </div>
        <div class="ctm-field">
          <label for="<?php echo $id; ?>-email_field"><?php echo esc_html( __( 'Contact Email:', 'contact-form-7' ) ); ?></label><br />
          <input placeholder="your-name" type="text" id="<?php echo $id; ?>-email_field" name="<?php echo $id; ?>-email_field" class="wide" size="32" value="<?php echo esc_attr( $formreactor['email_field'] ); ?>" />
          <cite>e.g. the field name used to capture the leads name as it will appear in the call log</cite>
        </div>
        <div class="ctm-field">
          <label for="<?php echo $id; ?>-caller_number"><?php echo esc_html( __( 'Phone Number:', 'contact-form-7' ) ); ?></label><br />
          <input placeholder="your-phone-number" type="text" id="<?php echo $id; ?>-caller_number" name="<?php echo $id; ?>-caller_number" class="wide" size="32" value="<?php echo esc_attr( $formreactor['phone_field'] ); ?>" />
          <cite>e.g. the field name used to capture the leads phone number</cite>
        </div>
        <div class="pseudo-hr"></div>
        <div class="ctm-field">
          <label for="<?php echo $id; ?>-formreactor"><?php echo esc_html( __( 'FormReactor', 'contact-form-7' ) ); ?></label><br />
          <input type="text" id="<?php echo $id; ?>-formreactor" name="<?php echo $id; ?>-formreactor" class="wide formreactor" size="68" value="<?php echo esc_attr( $formreactor['formreactor'] ); ?>" />
          <cite>the CallTrackingMetrics FormReactor to associate with this form.</cite>
        </div>
        <div class="pseudo-hr"></div>
      </div>
<?php } else { ?>
      <div class="half-left">
        <strong>to use this integration you must first provide your Account API keys under the settings integration panel in WordPress.</strong>
        <div class="pseudo-hr"></div>
      </div>
<?php } ?>
      <div class="half-right">
        <p>
          FormReactor integration allows you to use ContactForm7 and attach your form to a CallTrackingMetrics FormReactor call flow. 
          This allows you to capture form leads into your call log an optionally trigger a phone call either to a sales agent or to the customer filling out the form.
        </p>
        <p>
          To configure the FormReactor integration you must capture the leads phone number as a required field.
        </p>
      </div>
      <br class="clear" />
    </div>
<?php
  }

  function add_javascripts() {
    global $parent_file;

    if ( $parent_file == 'index.php') {
      echo '<script src="//cdnjs.cloudflare.com/ajax/libs/highcharts/4.0.4/highcharts.js"></script>';
      echo '<script src="//cdnjs.cloudflare.com/ajax/libs/mustache.js/0.7.2/mustache.min.js"></script>';
    }
    if ( $parent_file == 'wpcf7') {
      $ctm_api_auth_token = $this->check_token();
      echo '<link type="text/css" rel="stylesheet" media="all" href="//cdnjs.cloudflare.com/ajax/libs/select2/3.5.1/select2.min.css"/>';
      echo '<script src="//cdnjs.cloudflare.com/ajax/libs/select2/3.5.1/select2.min.js"></script>';
?>
    <script>
function setupMultiPicker(selector, object_type, placeholder) {

  if (typeof(selector) == 'string') {
    selector = jQuery(selector);
  }

  var optionsForSelect = {
    placeholder: placeholder,
    minimumInputLength: 0,
    multiple: false,
    quietMillis: 100,
    ajax: {
      url: function() { return '<?php echo $this->ctm_host ?>/api/v1/lookup.json?auth_token=<?php echo $ctm_api_auth_token; ?>' },
      dataType: 'json',
      data: function(term, page) {
        return { search: term, object_type: object_type, page: page };
      },
      results: function (data, page) {
        var more = (page * data.per_page) < data.total;
        return {results: data.results.map(function(res) {
          return {text: res.name, id: res.id};
        }), more: more};
      },
    },
    initSelection: function(element, callback) {
      var val = jQuery(element).val();
      var ids = decodeURIComponent(val).toString().split(",");
      if (ids.length) {
        jQuery.post("<?php echo $this->ctm_host ?>/api/v1/lookupids.json?idstr=1&amp;auth_token=<?php echo $ctm_api_auth_token; ?>", {object_type: object_type, ids: ids}, function(res) {
          var r = res.results[0];
          callback({id: r.id, text: r.name});
        },'json');
      }
    }
  };

  return selector.select2(optionsForSelect)
}
function readyForm(id) {
  console.log("ready:", id);
  jQuery.get("<?php echo $this->ctm_host ?>/api/v1/form_reactors/" + id, {auth_token: "<?php echo $ctm_api_auth_token; ?>"}, function(res) {
    console.log("data:", res);
  });
}
      jQuery(function() {
        setupMultiPicker("input.formreactor", "form_reactor", "Choose Form").on("change", function(evt) {
          readyForm(evt.val);
          console.log("form:", evt.val, evt.added, evt.removed);
        }).on("select2-loaded", function(evt) {
          console.log("form:", evt.items);
        });
        var id = jQuery("input.formreactor").val();
        if (id) { readyForm(id); }
      });
    </script>
<?php
    }
  }

  function call_tracking_metrics_script() {
    if (!is_admin()) {
      echo get_option('call_track_account_script'); 
    }
  }

  function attach_call_tracking_configuration() {
    if ( current_user_can( 'manage_options' ) ) {
      add_options_page('CallTrackingMetrics', 'CallTrackingMetrics', 'administrator', __FILE__, array(&$this,'settings_page'));
      if ($this->is_api_enabled()) {
        add_action('wp_dashboard_setup', array(&$this, 'install_dash_widget'));
      }
    }
  }

  function install_dash_widget() {
    wp_add_dashboard_widget("ctm_dash", "CallTrackingMetrics", array(&$this, 'admin_dashboard_plugin'));
  }

  // show a snapshot of recent call activity and aggregate stats
  function admin_dashboard_plugin() {
    if (!$this->is_api_enabled()) {
      ?>
        <h4>API keys Must be installed first</h4>
        <p>Install API keys under the settings menu</p>
      <?php
      return;
    }
    $ctm_api_key    = get_option('ctm_api_key'); 
    $ctm_api_secret = get_option('ctm_api_secret'); 
    $stats          = get_option('ctm_api_stats');
    $dates          = array();
    $end            = date('Y-m-d');
    $count          = 0;

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
          data.push(0); // zero fill
        }
        for (var c in calls) {
          data[categories.indexOf(c)] = calls[c][0];
        }
        var series = [{
                        name: 'Calls', data: data,
                        pointInterval: 24 * 3600 * 1000,
                        pointStart: Date.parse(categories[0])
                      }];
        var chart = new Highcharts.Chart({
          credits: { enabled: false },
          chart: { type: 'column', renderTo: $("#ctm_dash .stats").get(0), plotBackgroundColor:null, backgroundColor: 'transparent' },
          yAxis: { min: 0, title: { text: "Calls" } },
          title: { text: 'Last 30 Days' },
          legend: { enabled: false },
          //tooltip: { formatter: function() { return '<b>'+ this.x +'</b><br/> '+ this.y; } },
          xAxis: {
            type: 'datetime',
            minRange: 30 * 24 * 3600000 // last 30 days
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

    $stats_url = "{$this->ctm_host}/api/v1/accounts/$ctm_api_auth_account/reports.json?auth_token=$ctm_api_auth_token";

    $req = new WP_Http;
    $res = $req->request($stats_url, array_merge($this->request_options, array('method' => 'GET')));
    if (isset($res) && is_array($res)) {
      $stats = $res['body'];
      if (isset($stats)) {
        update_option("ctm_api_stats", json_decode($stats));
        update_option("ctm_api_stats_expires", date('Y-m-d H:i:s', strtotime('+10 minutes')));
      }
    }
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
    return $ctm_api_auth_token;
  }

  function refresh_token($ctm_api_key, $ctm_api_secret) {
    $url = "{$this->ctm_host}/api/v1/authentication.json";
    $args = array("token" => $ctm_api_key, "secret" => $ctm_api_secret);
    $req = new WP_Http;
    $res = $req->request($url, array_merge($this->request_options, array('method' => 'POST', 'body' => $args)));
    if (is_wp_error($res)) {
      error_log("error connecting to ctm {} see: {$this->ctm_host}");
      update_option('ctm_api_connect_failed', "Error connecting please double check your API credentials");
      delete_option('ctm_api_auth_token');
      delete_option('ctm_api_auth_expires');
      delete_option('ctm_api_auth_account');
    } else if ($res && isset($res['body'])) {
      $data = json_decode($res['body']);
      if (isset($data) && $data && $data->success) {
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
          <strong><label for="call_track_account_script"><?php _e("CallTrackingMetrics Script Tag"); ?></label></strong><br/>
          <textarea style="font-size:12px" cols="58" rows="10" id="call_track_account_script" name="call_track_account_script"><?php echo get_option('call_track_account_script'); ?></textarea>
          <cite>Embed the exact code snipet provided here: <a href="https://calltrackingmetrics.com/embed_code">embed code</a></cite>
        </td>
        <td style="width:100%;vertical-align:middle">
          <p>
            This is the embed script provided by CallTrackingMetrics.
          </p>
          <p>Once you have the embed code saved in WP - you can control your tracking sources <a href="https://calltrackingmetrics.com/sources">here</a> and view your reports <a href="https://calltrackingmetrics.com/reports/overview">here</a>.
        </td>
      </tr>
      <tr valign="top">
        <td>
          <?php if ($this->is_api_enabled()) { ?>
          <div style="background-color:#8cff8b;border-color:#fc0;padding:12px;text-align:center;font-size:18px;">
            API keys verfied
          </div>
          <?php } else if (get_option('ctm_api_connect_failed')) { ?>
          <div style="background-color:red;border-color:red;padding:12px;text-align:center;font-size:18px;color:#fff">
            API keys invalid
          </div>
          <?php } else { ?>
          <div style="background-color:#F7D054;border-color:#F7e084;padding:12px;text-align:center;font-size:18px;color:#fff">
            API not enabled
          </div>
          <br/>
          <br/>
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
          <h3>API integration provides</h3>
          <ul>
            <li>&bull; Populate stats in your Wordpress Dashboard.</li>
            <li>&bull; Provide FormReactor integration with Contact Form 7</li>
          </ul>
          <h4>Don't have an account?  <a href="https://calltrackingmetrics.com/plans">Pricing &amp; Sign up</a> now it only takes a few minutes.</h4>
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
