<?php
/**
 * @package wpml-core
 */

if(!class_exists('WPML_Config')) {
	require ICL_PLUGIN_PATH . '/inc/wpml-config/wpml-config.class.php';
}
if(!class_exists('TM_Notification')) {
	require_once ICL_PLUGIN_PATH . '/inc/translation-management/tm-notification.class.php';
}

class WPML_Translator {
	var $ID;
	var $display_name;
	var $user_login;
	var $language_pairs;

	public function __get( $property ) {
		if ( $property == 'translator_id' ) {
			return $this->ID;
		}
	}

	public function __set( $property, $value ) {
		if ( $property == 'translator_id' ) {
			$this->ID = $value;
		}
	}
}

class TranslationManagement{
	private $remote_target_languages;

	/**
	 * @var WPML_Translator
	 */
	private $selected_translator;
	/**
	 * @var WPML_Translator
	 */
	private $current_translator;
	private $messages                 = array();
	public $dashboard_select = array();
	public $settings;
	public $admin_texts_to_translate = array();
	private $translation_jobs_basket;

	function __construct(){
		//Translation Management is not installed: get out from here
//		if(!defined('WPML_TM_FOLDER')) return;

		$this->selected_translator     = new WPML_Translator();
		$this->selected_translator->ID = 0;
		$this->current_translator      = new WPML_Translator();
		$this->current_translator->ID  = 0;

		add_action('init', array($this, 'init'), 1500);

		if(isset($_GET['icl_tm_message'])){
			$this->add_message( array(
				'type' => isset($_GET['icl_tm_message_type']) ? $_GET['icl_tm_message_type'] : 'updated',
				'text'  => $_GET['icl_tm_message']
			                    ) );
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action('save_post', array($this, 'save_post_actions'), 110, 2); // calling *after* the Sitepress actions
		add_action('delete_post', array($this, 'delete_post_actions'), 1, 1); // calling *before* the Sitepress actions
		add_action( 'edit_term', array( $this, 'edit_term' ), 120, 2 ); // calling *after* the Sitepress actions
		add_action('icl_ajx_custom_call', array($this, 'ajax_calls'), 10, 2);
		add_action('wp_ajax_show_post_content', array($this, '_show_post_content'));

		// 1. on Translation Management dashboard and jobs tabs
		// 2. on Translation Management dashboard tab (called without sm parameter as default page)
		// 3. Translations queue
		if ( ( isset( $_GET[ 'sm' ] ) && ( $_GET[ 'sm' ] == 'dashboard' || $_GET[ 'sm' ] == 'jobs' ) ) ||
			 ( isset( $_GET[ 'page' ] ) && preg_match( '@/menu/main\.php$@', $_GET[ 'page' ] ) && !isset( $_GET[ 'sm' ] )  ) ||
		     ( isset( $_GET[ 'page' ] ) && preg_match( '@/menu/translations-queue\.php$@', $_GET[ 'page' ] ) ) ) {
			@session_start();
		}
		add_filter('icl_additional_translators', array($this, 'icl_additional_translators'), 99, 3);

		add_action('user_register', array($this, 'clear_cache'));
		add_action('profile_update', array($this, 'clear_cache'));
		add_action('delete_user', array($this, 'clear_cache'));
		add_action('added_existing_user', array($this, 'clear_cache'));
		add_action('remove_user_from_blog', array($this, 'clear_cache'));

		add_action('admin_print_scripts', array($this, '_inline_js_scripts'));

		add_action('wp_ajax_icl_tm_user_search', array($this, '_user_search'));

		add_action('wp_ajax_icl_tm_abort_translation', array($this, 'abort_translation'));

		add_action('display_basket_notification', array($this, 'display_basket_notification'), 10, 1);
		add_action('wpml_tm_send_post_jobs', array($this, 'send_posts_jobs'), 10, 5);
		add_action('wpml_tm_send_jobs', array($this, 'send_jobs'), 10, 1);
	}

	public function admin_enqueue_scripts( $hook ) {
		if(!defined('WPML_TM_FOLDER')) {
			return;
		}
		$valid_hook = 'wpml_page_' . WPML_TM_FOLDER . '/menu/main';
		$submenu = filter_input(INPUT_GET, 'sm');
		if (!defined('WPML_TM_FOLDER') || ($hook != $valid_hook && !$submenu)) {
			return;
		}
		if ( !$submenu ) {
			$submenu = 'dashboard';
		}

		switch ( $submenu ) {
			case 'jobs':
				wp_register_style( 'translation-jobs', WPML_TM_URL . '/res/css/translation-jobs.css', array(), WPML_TM_VERSION );

				wp_register_script( 'headjs', '//cdnjs.cloudflare.com/ajax/libs/headjs/1.0.3/head.min.js', array(), false, true );
				wp_register_script( 'translation-jobs-main', WPML_TM_URL . '/res/js/listing/main.js', array('jquery', 'backbone', 'headjs'), WPML_TM_VERSION, true );

				$l10n = array(
						'TJ_JS'   => array(
								'listing_lib_path' => WPML_TM_URL . '/res/js/listing/',
						),
				);

				wp_enqueue_style( 'translation-jobs');

				wp_localize_script( 'translation-jobs-main', 'Translation_Jobs_settings', $l10n );
				wp_enqueue_script( 'translation-jobs-main');

				break;
			case 'translators':
				wp_register_style( 'translation-translators', WPML_TM_URL . '/res/css/translation-translators.css', array(), WPML_TM_VERSION );
				wp_enqueue_style( 'translation-translators');
				break;
			default:
				wp_register_style( 'translation-dashboard', WPML_TM_URL . '/res/css/translation-dashboard.css', array(), WPML_TM_VERSION );
				wp_enqueue_style( 'translation-dashboard');

		}

	}

	public static function get_batch_name( $batch_id ) {
		$batch_data = self::get_batch_data( $batch_id );
		if ( ! $batch_data || ! isset( $batch_data->batch_name ) ) {
			$batch_name = __( 'No Batch', 'wpml-translation-management' );
		} else {
			$batch_name = $batch_data->batch_name;
		}

		return $batch_name;
	}

	public static function get_batch_url( $batch_id ) {
		$batch_data = self::get_batch_data( $batch_id );
		$batch_url  = '';
		if ( $batch_data && isset( $batch_data->tp_id ) && $batch_data->tp_id != 0 ) {
			$batch_url = OTG_TRANSLATION_PROXY_URL . "/projects/{$batch_data->tp_id}/external";
		}

		return $batch_url;
	}

	public static function get_batch_last_update( $batch_id ) {
		$batch_data = self::get_batch_data($batch_id);

		return $batch_data ? $batch_data->last_update : false;
	}

	public static function get_batch_tp_id( $batch_id ) {
		$batch_data = self::get_batch_data($batch_id);

		return $batch_data ? $batch_data->tp_id : false;
	}

	public static function get_batch_data( $batch_id ) {
		$cache_key   = $batch_id;
		$cache_group = 'get_batch_data';
		$cache_found = false;

		$batch_data = wp_cache_get( $cache_key, $cache_group, false, $cache_found );

		if ( $cache_found ) {
			return $batch_data;
		}

		global $wpdb;
		$batch_data_sql      = "SELECT * FROM {$wpdb->prefix}icl_translation_batches WHERE id=%d";
		$batch_data_prepared = $wpdb->prepare( $batch_data_sql, array( $batch_id ) );
		$batch_data          = $wpdb->get_row( $batch_data_prepared );

		wp_cache_set( $cache_key, $batch_data, $cache_group );

		return $batch_data;
	}

	function save_settings() {
		global $sitepress;

		$iclsettings[ 'translation-management' ] = $this->settings;
		$cpt_sync_option = $sitepress->get_setting( 'custom_posts_sync_option', array());
		$cpt_sync_option = (bool)$cpt_sync_option === false
			? $sitepress->get_setting( 'custom-types_sync_option', array()) : $cpt_sync_option;
		foreach ( $cpt_sync_option as $k => $v ) {
			$iclsettings[ 'custom_posts_sync_option' ][ $k ] = $v;
		}
		$iclsettings[ 'translation-management' ]['custom-types_readonly_config']
			= isset($iclsettings[ 'translation-management' ]['custom-types_readonly_config'])
			? $iclsettings[ 'translation-management' ]['custom-types_readonly_config'] : array();
		foreach($iclsettings[ 'translation-management' ]['custom-types_readonly_config'] as $k => $v){
			$iclsettings[ 'custom_posts_sync_option' ][ $k ] = $v;
		}
		$sitepress->save_settings ( $iclsettings );
	}

	function init(){

		global $wpdb, $current_user, $sitepress_settings, $sitepress;

		$this->settings =& $sitepress_settings['translation-management'];

		//logic for syncing comments
		if($sitepress->get_option('sync_comments_on_duplicates')){
			add_action('delete_comment', array($this, 'duplication_delete_comment'));
			add_action('edit_comment', array($this, 'duplication_edit_comment'));
			add_action('wp_set_comment_status', array($this, 'duplication_status_comment'), 10, 2);
			add_action('wp_insert_comment', array($this, 'duplication_insert_comment'), 100);
		}

		$this->initial_custom_field_translate_states();

		// defaults
		if ( !isset( $this->settings[ 'notification' ][ 'new-job' ] ) ) {
			$this->settings[ 'notification' ][ 'new-job' ] = ICL_TM_NOTIFICATION_IMMEDIATELY;
		}
		if ( !isset( $this->settings[ 'notification' ][ 'completed' ] ) ) {
			$this->settings[ 'notification' ][ 'completed' ] = ICL_TM_NOTIFICATION_IMMEDIATELY;
		}
		if ( !isset( $this->settings[ 'notification' ][ 'resigned' ] ) ) {
			$this->settings[ 'notification' ][ 'resigned' ] = ICL_TM_NOTIFICATION_IMMEDIATELY;
		}
		if ( !isset( $this->settings[ 'notification' ][ 'dashboard' ] ) ) {
			$this->settings[ 'notification' ][ 'dashboard' ] = true;
		}
		if ( !isset( $this->settings[ 'notification' ][ 'purge-old' ] ) ) {
			$this->settings[ 'notification' ][ 'purge-old' ] = 7;
		}

		if ( !isset( $this->settings[ 'custom_fields_translation' ] ) ) {
			$this->settings[ 'custom_fields_translation' ] = array();
		}
		if ( !isset( $this->settings[ 'doc_translation_method' ] ) ) {
			$this->settings[ 'doc_translation_method' ] = ICL_TM_TMETHOD_MANUAL;
		}

		get_currentuserinfo();
		$user = false;
		if(isset($current_user->ID)){
			$user = new WP_User($current_user->ID);
		}

		if ( !$user || empty( $user->data ) ) {
			return;
		}

		$ct                 = new WPML_Translator();
		$ct->ID             = $current_user->ID;
		$ct->user_login     = isset($user->data->user_login) ? $user->data->user_login : false;
		$ct->display_name   = isset( $user->data->display_name ) ? $user->data->display_name : $ct->user_login;
		$ct->language_pairs = get_user_meta( $current_user->ID, $wpdb->prefix . 'language_pairs', true );
		if ( empty( $ct->language_pairs ) ) {
			$ct->language_pairs = array();
		}

		$this->current_translator = (object)$ct;

		WPML_Config::load_config();

		if(isset($_POST['icl_tm_action'])){
			$this->process_request($_POST['icl_tm_action'], $_POST);
		}elseif(isset($_GET['icl_tm_action'])){
			$this->process_request($_GET['icl_tm_action'], $_GET);
		}

		if($GLOBALS['pagenow']=='edit.php'){ // use standard WP admin notices
			add_action('admin_notices', array($this, 'show_messages'));
		}else{                               // use custom WP admin notices
			add_action('icl_tm_messages', array($this, 'show_messages'));
		}

		if(isset($_GET['page']) && basename($_GET['page']) == 'translations-queue.php' && isset($_GET['job_id'])){
			add_filter('admin_head',array($this, '_show_tinyMCE'));
		}

		//if(!isset($this->settings['doc_translation_method'])){
		if(isset($this->settings['doc_translation_method']) && $this->settings['doc_translation_method'] < 0 ){
			if(isset($_GET['sm']) && $_GET['sm']=='mcsetup' && isset($_GET['src']) && $_GET['src']=='notice'){
						$this->settings['doc_translation_method'] = ICL_TM_TMETHOD_MANUAL;
						$this->save_settings();
			}else{
				add_action('admin_notices', array($this, '_translation_method_notice'));
			}
		}

		if(defined('WPML_TM_VERSION') && isset($_GET['page']) && $_GET['page'] == WPML_TM_FOLDER. '/menu/main.php' && isset($_GET['sm']) && $_GET['sm'] == 'translators'){
			$lang_status = TranslationProxy_Translator::get_icl_translator_status();
			if ( !empty( $lang_status ) ){
				$sitepress->save_settings($lang_status);
			}
		}

		// default settings
		if(empty($this->settings['doc_translation_method']) || !defined('WPML_TM_VERSION')){
			$this->settings['doc_translation_method'] = ICL_TM_TMETHOD_MANUAL;
		}
	}

	function initial_custom_field_translate_states() {
		global $wpdb;

		$cf_keys_limit = 1000; // jic
		$custom_keys = $wpdb->get_col( "
			SELECT meta_key
			FROM $wpdb->postmeta
			GROUP BY meta_key
			ORDER BY meta_key
			LIMIT $cf_keys_limit" );

		$changed = false;

		foreach($custom_keys as $cfield) {
			if(empty($this->settings['custom_fields_translation'][$cfield]) || $this->settings['custom_fields_translation'][$cfield] == 0) {
				// see if a plugin handles this field
				$override = apply_filters('icl_cf_translate_state', 'nothing', $cfield);
				switch($override) {
					case 'nothing':
						break;

					case 'ignore':
						$changed = true;
						$this->settings['custom_fields_translation'][$cfield] = 3;
						break;

					case 'translate':
						$changed = true;
						$this->settings['custom_fields_translation'][$cfield] = 2;
						break;

					case 'copy':
						$changed = true;
						$this->settings['custom_fields_translation'][$cfield] = 1;
						break;
				}

			}
		}
		if ($changed) {
			$this->save_settings();
		}
	}

	function _translation_method_notice(){
		echo '<div class="error fade"><p id="icl_side_by_site">'.sprintf(__('New - side-by-site translation editor: <a href="%s">try it</a> | <a href="#cancel">no thanks</a>.', 'sitepress'),
				admin_url('admin.php?page='.WPML_TM_FOLDER.'/menu/main.php&sm=mcsetup&src=notice')) . '</p></div>';
	}

	function _show_tinyMCE() {
		wp_print_scripts('editor');
		//add_filter('the_editor', array($this, 'editor_directionality'), 9999);
		add_filter('tiny_mce_before_init', array($this, '_mce_set_direction'), 9999);
		add_filter('mce_buttons', array($this, '_mce_remove_fullscreen'), 9999);

		if (version_compare($GLOBALS['wp_version'], '3.1.4', '<=') && function_exists('wp_tiny_mce'))
		try{
			/** @noinspection PhpDeprecationInspection */
			@wp_tiny_mce();
		} catch(Exception $e) {  /*don't do anything with this */ }
	}

	function _mce_remove_fullscreen($options){
		foreach($options as $k=>$v) if($v == 'fullscreen') unset($options[$k]);
		return $options;
	}

	function _inline_js_scripts(){
		// remove fullscreen mode
		if(defined('WPML_TM_FOLDER') && isset($_GET['page']) && $_GET['page'] == WPML_TM_FOLDER . '/menu/translations-queue.php' && isset($_GET['job_id'])){
			?>
			<script type="text/javascript">addLoadEvent(function(){jQuery('#ed_fullscreen').remove();});</script>
			<?php
		}
	}


	function _mce_set_direction($settings) {
		$job = $this->get_translation_job((int)$_GET['job_id'], false, true);
		if (!empty($job)) {
			$rtl_translation = in_array($job->language_code, array('ar','he','fa'));
			if ($rtl_translation) {
				$settings['directionality'] = 'rtl';
			} else {
				$settings['directionality'] = 'ltr';
			}
		}
		return $settings;
	}

	function process_request($action, $data){
		$data = stripslashes_deep($data);
		switch($action){
			case 'add_translator':
				if(wp_verify_nonce($data['add_translator_nonce'], 'add_translator') ){
					// Initial adding
					if (isset($data['from_lang']) && isset($data['to_lang'])) {
					  $data['lang_pairs'] = array();
					  $data['lang_pairs'][$data['from_lang']] = array($data['to_lang'] => 1);
					}
					$this->add_translator($data['user_id'], $data['lang_pairs']);
					$_user = new WP_User($data['user_id']);
					wp_redirect('admin.php?page='.WPML_TM_FOLDER.'/menu/main.php&sm=translators&icl_tm_message='.urlencode(sprintf(__('%s has been added as a translator for this site.','sitepress'),$_user->data->display_name)).'&icl_tm_message_type=updated');
				}
				break;
			case 'edit_translator':
				if(wp_verify_nonce($data['edit_translator_nonce'], 'edit_translator')){
					$this->edit_translator($data['user_id'], isset($data['lang_pairs']) ? $data['lang_pairs'] : array());
					$_user = new WP_User($data['user_id']);
					wp_redirect('admin.php?page='.WPML_TM_FOLDER.'/menu/main.php&sm=translators&icl_tm_message='.urlencode(sprintf(__('Language pairs for %s have been edited.','sitepress'),$_user->data->display_name)).'&icl_tm_message_type=updated');
				}
				break;
			case 'remove_translator':
				if ( wp_verify_nonce( $data[ 'remove_translator_nonce' ], 'remove_translator' ) ) {
					$this->remove_translator($data['user_id']);
					$_user = new WP_User($data['user_id']);
					wp_redirect('admin.php?page='.WPML_TM_FOLDER.'/menu/main.php&sm=translators&icl_tm_message='.urlencode(sprintf(__('%s has been removed as a translator for this site.','sitepress'),$_user->data->display_name)).'&icl_tm_message_type=updated');
				}
				break;
			case 'edit':
                $this->selected_translator->ID = intval($data['user_id']);
				break;
			case 'dashboard_filter':
				$_SESSION['translation_dashboard_filter'] = $data['filter'];
				wp_redirect('admin.php?page='.WPML_TM_FOLDER . '/menu/main.php&sm=dashboard');
				break;
		   case 'sort':
				if(isset($data['sort_by'])) $_SESSION['translation_dashboard_filter']['sort_by'] = $data['sort_by'];
				if(isset($data['sort_order'])) $_SESSION['translation_dashboard_filter']['sort_order'] = $data['sort_order'];
				break;
		   case 'reset_filters':
				unset($_SESSION['translation_dashboard_filter']);
				break;
            case 'add_jobs':
	            if ( isset( $data[ 'iclnonce' ] ) && wp_verify_nonce( $data[ 'iclnonce' ], 'pro-translation-icl' ) ) {
		            TranslationProxy_Basket::add_posts_to_basket( $data );
		            do_action( 'wpml_tm_add_to_basket', $data );
	            }
				break;
		   case 'jobs_filter':
				$_SESSION['translation_jobs_filter'] = $data['filter'];
				wp_redirect('admin.php?page='.WPML_TM_FOLDER . '/menu/main.php&sm=jobs');
				break;
		   case 'ujobs_filter':
				$_SESSION['translation_ujobs_filter'] = $data['filter'];
				wp_redirect('admin.php?page='.WPML_TM_FOLDER . '/menu/translations-queue.php');
				break;
		   case 'save_translation':
				if(!empty($data['resign'])){
					$this->resign_translator($data['job_id']);
					wp_redirect(admin_url('admin.php?page='.WPML_TM_FOLDER.'/menu/translations-queue.php&resigned='.$data['job_id']));
					exit;
				}else{
					$this->save_translation($data);
				}
				break;
		   case 'save_notification_settings':
				$this->settings['notification'] = $data['notification'];
				$this->save_settings();
			   $this->add_message( array(
				                       'type' => 'updated',
				                       'text' => __( 'Preferences saved.', 'sitepress' )
			                       ) );
				break;
		   case 'create_job':
				global $current_user;
				if(!isset($this->current_translator->ID) && isset($current_user->ID)){
					$this->current_translator->ID  = $current_user->ID;
				}
				$data['translator'] = $this->current_translator->ID;

				$job_ids = $this->send_jobs($data);
				wp_redirect('admin.php?page='.WPML_TM_FOLDER . '/menu/translations-queue.php&job_id=' . array_pop($job_ids));
				break;
		   case 'cancel_jobs':
				 if(isset($data['icl_translation_id'])) {
					 $this->cancel_translation_request( $data[ 'icl_translation_id' ] );
					 $this->add_message( array(
					'type'=>'updated',
					'text' => __('Translation requests cancelled.', 'sitepress')
					 ));
				 } else {
					 $this->add_message(array(
							 'type' => 'updated',
							 'text' => __( 'No Translation requests selected.', 'sitepress' )
					 ));
				 }
				break;
		}
	}

	function ajax_calls( $call, $data ) {
		global $wpdb, $sitepress;
		switch ( $call ) {
			case 'assign_translator':

				$translator_data = TranslationProxy_Service::get_translator_data_from_wpml($data[ 'translator_id' ]);
				$service_id = $translator_data['translation_service'];
				$translator_id = $translator_data['translator_id'];
				$assign_translation_job = $this->assign_translation_job( $data[ 'job_id' ], $translator_id, $service_id );
				if ( $assign_translation_job ) {
					$translator_edit_link = '';
					if ( $translator_id ) {
						if ( $service_id == TranslationProxy::get_current_service_id() ) {
						$job = $this->get_translation_job( $data[ 'job_id' ] );
						global $ICL_Pro_Translation;
							$ICL_Pro_Translation->send_post( $job->original_doc_id, array( $job->language_code ), $translator_id, $data[ 'job_id' ] );
							$project = TranslationProxy::get_current_project();

							$translator_edit_link =
									TranslationProxy_Popup::get_link( $project->translator_contact_iframe_url( $translator_id ), array( 'title' => __( 'Contact the translator', 'sitepress' ), 'unload_cb' => 'icl_thickbox_refresh' ) )
									. esc_html( TranslationProxy_Translator::get_translator_name( $translator_id ) )
									. "</a> ($project->service->name)";
					} else {
						$translator_edit_link =
									'<a href="'
									. TranslationManagement::get_translator_edit_url( $data[ 'translator_id' ] )
									. '">'
									. esc_html( $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID=%d", $data[ 'translator_id' ] ) ) )
									. '</a>';
					}
					}
					echo wp_json_encode( array( 'error' => 0, 'message' => $translator_edit_link, 'status' => TranslationManagement::status2text( ICL_TM_WAITING_FOR_TRANSLATOR ), 'service' => $service_id ) );
				} else {
					echo wp_json_encode( array( 'error' => 1 ) );
				}
				break;
			case 'icl_cf_translation':
				if ( !empty( $data[ 'cf' ] ) ) {
					foreach ( $data[ 'cf' ] as $k => $v ) {
						$cft[ base64_decode( $k ) ] = $v;
					}
					if ( isset( $cft ) ) {
						$this->settings['custom_fields_translation'] = $cft;
						$this->save_settings();
					}
				}
				echo '1|';
				break;
			case 'icl_doc_translation_method':
				$this->settings['doc_translation_method'] = intval($data['t_method']);
				$sitepress->set_setting( 'doc_translation_method', $this->settings[ 'doc_translation_method' ] );
				$sitepress->save_settings( array( 'hide_how_to_translate' => empty( $data[ 'how_to_translate' ] ) ) );
				if (isset($data[ 'tm_block_retranslating_terms' ])) {
					$sitepress->set_setting( 'tm_block_retranslating_terms', $data[ 'tm_block_retranslating_terms' ] );
				} else {
					$sitepress->set_setting( 'tm_block_retranslating_terms', '' );
				}
				if (isset($data[ 'tm_block_retranslating_terms' ])) {
					$sitepress->set_setting( 'tm_block_retranslating_terms', $data[ 'tm_block_retranslating_terms' ] );
				} else {
					$sitepress->set_setting( 'tm_block_retranslating_terms', '' );
				}
				$this->save_settings();
				echo '1|';
				break;
			case 'reset_duplication':
				$this->reset_duplicate_flag( $_POST[ 'post_id' ] );
				break;
			case 'set_duplication':
				$new_id = $this->set_duplicate( $_POST[ 'post_id' ] );
				wp_send_json_success(array('id' => $new_id));
				break;
			case 'make_duplicates':
				$mdata[ 'iclpost' ] = array( $data[ 'post_id' ] );
				$langs              = explode( ',', $data[ 'langs' ] );
				foreach ( $langs as $lang ) {
					$mdata[ 'duplicate_to' ][ $lang ] = 1;
				}
				$this->make_duplicates( $mdata );
				break;
		}
	}

	/**
	 * @param $element_type_full
	 *
	 * @return mixed
	 */
	public function get_element_prefix( $element_type_full ) {
		$element_type_parts = explode( '_', $element_type_full );
		$element_type       = $element_type_parts[ 0 ];

		return $element_type;
	}

	/**
	 * @param int $job_id
	 *
	 * @return mixed
	 */
	public function get_element_type_prefix_from_job_id( $job_id ) {
		$job = $this->get_translation_job($job_id);

		return $job ? $this->get_element_type_prefix_from_job($job) : false;
	}

	/**
	 * @param $job
	 *
	 * @return mixed
	 */
	public function get_element_type_prefix_from_job( $job ) {
		$element_type        = $this->get_element_type( $job->trid );
		$element_type_prefix = $this->get_element_prefix( $element_type );

		return $element_type_prefix;
	}

	function show_messages(){
			$messages = $this->messages;
			if( !empty( $messages ) ){
            $displayed = array();

            foreach( $messages as $m){

                // if this message was already displayed, skip
                if (!empty($displayed[$m['type']]) and $displayed[$m['type']] == $m['text']) continue;

				echo '<div class="'.$m['type'].' below-h2"><p>' . $m['text'] . '</p></div>';

                // collect displayed message
                $displayed[$m['type']] = $m['text'];
			}
		}
	}

	/* TRANSLATORS */
	/* ******************************************************************************************** */
	function add_translator($user_id, $language_pairs){
		global $wpdb;

		$user = new WP_User($user_id);
		$user->add_cap('translate');

		$um = get_user_meta($user_id, $wpdb->prefix . 'language_pairs', true);
		if(!empty($um)){
			foreach($um as $fr=>$to){
				if(isset($language_pairs[$fr])){
					$language_pairs[$fr] = array_merge($language_pairs[$fr], $to);
				}

			}
		}

		update_user_meta($user_id, $wpdb->prefix . 'language_pairs',  $language_pairs);
		$this->clear_cache();
	}

	function edit_translator($user_id, $language_pairs){
		global $wpdb;
		$_user = new WP_User($user_id);
		if(empty($language_pairs)){
			$this->remove_translator($user_id);
			wp_redirect('admin.php?page='.WPML_TM_FOLDER.'/menu/main.php&sm=translators&icl_tm_message='.
				urlencode(sprintf(__('%s has been removed as a translator for this site.','sitepress'),$_user->data->display_name)).'&icl_tm_message_type=updated'); exit;
		}
		else{
			if(!$_user->has_cap('translate')) $_user->add_cap('translate');
			update_user_meta($user_id, $wpdb->prefix . 'language_pairs',  $language_pairs);
		}
	}

	function remove_translator($user_id){
		global $wpdb;
		$user = new WP_User($user_id);
		$user->remove_cap('translate');
		delete_user_meta($user_id, $wpdb->prefix . 'language_pairs');
		$this->clear_cache();
	}

	function is_translator( $user_id, $args = array() )
	{
		extract( $args, EXTR_OVERWRITE );

		global $wpdb;
		$user = new WP_User( $user_id );

		$is_translator = $user->has_cap( 'translate' );

		// check if user is administrator and return true if he is
		$user_caps = $user->allcaps;
		if ( isset( $user_caps[ 'activate_plugins' ] ) && $user_caps[ 'activate_plugins' ] == true ) {
			$is_translator = true;
		} else {

			if ( isset( $lang_from ) && isset( $lang_to ) ) {
				$um            = get_user_meta( $user_id, $wpdb->prefix . 'language_pairs', true );
				$is_translator = $is_translator && isset( $um[ $lang_from ] ) && isset( $um[ $lang_from ][ $lang_to ] ) && $um[ $lang_from ][ $lang_to ];
			}
			if ( isset( $job_id ) ) {
				$translator_id = $wpdb->get_var( $wpdb->prepare( "
							SELECT j.translator_id
								FROM {$wpdb->prefix}icl_translate_job j
								JOIN {$wpdb->prefix}icl_translation_status s ON j.rid = s.rid
							WHERE job_id = %d AND s.translation_service='local'
						", $job_id ) );

				$is_translator = $is_translator && ( ( $translator_id == $user_id ) || empty( $translator_id ) );
			}

		}

		return $is_translator;
	}

	function set_default_translator($id, $from, $to, $type = 'local'){
		global $sitepress, $sitepress_settings;
		$iclsettings['default_translators'] = isset($sitepress_settings['default_translators']) ? $sitepress_settings['default_translators'] : array();
		$iclsettings['default_translators'][$from][$to] = array('id'=>$id, 'type'=>$type);
		$sitepress->save_settings($iclsettings);
	}

	function get_default_translator($from, $to){
		global $sitepress_settings;
		if(isset($sitepress_settings['default_translators'][$from][$to])){
			$dt = $sitepress_settings['default_translators'][$from][$to];
		}else{
			$dt = array();
		}
		return $dt;
	}

	public static function get_blog_not_translators(){
		global $wpdb;
		$cached_translators = get_option($wpdb->prefix . 'icl_non_translators_cached', array());
		if (!empty($cached_translators)) {
			return $cached_translators;
		}
		$sql = "SELECT u.ID, u.user_login, u.display_name, m.meta_value AS caps
			FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON u.id=m.user_id AND m.meta_key = '{$wpdb->prefix}capabilities' ORDER BY u.display_name";
		$res = $wpdb->get_results($sql);

		$users = array();
		foreach($res as $row){
			$caps = @unserialize($row->caps);
			if(!isset($caps['translate'])){
				$users[] = $row;
			}
		}
		update_option($wpdb->prefix . 'icl_non_translators_cached', $users);
		return $users;
	}

	public static function get_blog_translators($args = array()){
		global $wpdb;
		$args_default = array('from'=>false, 'to'=>false);
		extract($args_default);
		extract($args, EXTR_OVERWRITE);

		$cached_translators = get_option($wpdb->prefix . 'icl_translators_cached', array());

		if (empty($cached_translators)) {
			$sql = "SELECT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON u.id=m.user_id AND m.meta_key = '{$wpdb->prefix}language_pairs' ORDER BY u.display_name";
			$res = $wpdb->get_results($sql);
			update_option($wpdb->prefix . 'icl_translators_cached', $res);
		} else {
			$res = $cached_translators;
		}

		$users = array();
		foreach($res as $row){
			$user = new WP_User($row->ID);
			$user->language_pairs = (array)get_user_meta($row->ID, $wpdb->prefix.'language_pairs', true);
			if(!empty($from) && !empty($to) && (!isset($user->language_pairs[$from][$to]) || !$user->language_pairs[$from][$to])){
				continue;
			}
			if($user->has_cap('translate')){
				$users[] = $user;
			}
		}

		return $users;
	}

	/**
	 * @return WPML_Translator
	 */
	function get_selected_translator(){
		global $wpdb;
		if ( $this->selected_translator && $this->selected_translator->ID ) {
			$user                                      = new WP_User( $this->selected_translator->ID );
			$this->selected_translator->display_name   = $user->data->display_name;
			$this->selected_translator->user_login     = $user->data->user_login;
			$this->selected_translator->language_pairs = get_user_meta( $this->selected_translator->ID, $wpdb->prefix . 'language_pairs', true );
		}else{
			$this->selected_translator->ID = 0;
		}

		return $this->selected_translator;
	}

	/**
	 * @return WPML_Translator
	 */
	function get_current_translator(){
		return $this->current_translator;
	}

	public static function get_translator_edit_url($translator_id){
		$url = '';
		if(!empty($translator_id)){
			$url = 'admin.php?page='. WPML_TM_FOLDER .'/menu/main.php&amp;sm=translators&icl_tm_action=edit&amp;user_id='. $translator_id;
		}
		return $url;
	}

	public static function translators_dropdown( $args = array() ) {
		$dropdown = '';

		/** @var $from string|false */
		/** @var $to string|false */
		/** @var $classes string|false */
		/** @var $id string|false */
		/** @var $name string|false */
		/** @var $selected bool */
		/** @var $echo bool */
		/** @var $services array */
		/** @var $show_service bool */
		/** @var $disabled bool */
		/** @var $default_name bool|string */
		/** @var $local_only bool */

		//set default value for variables
		$from         = false;
		$to           = false;
		$id           = 'translator_id';
		$name         = 'translator_id';
		$selected     = 0;
		$echo         = true;
		$services     = array( 'local' );
		$show_service = true;
		$disabled     = false;
		$default_name = false;
		$local_only   = false;

		extract( $args, EXTR_OVERWRITE );

		$translators = array();

		try {

			$translation_service      = TranslationProxy::get_current_service();
			$translation_service_id   = TranslationProxy::get_current_service_id();
			$translation_service_name = TranslationProxy::get_current_service_name();
			$is_service_authenticated = TranslationProxy::is_service_authenticated();

			//if translation service does not support translators choice, always shows first available
			if ( isset( $translation_service->id ) && ! TranslationProxy::translator_selection_available() && $is_service_authenticated ) {
				$translators[ ] = (object) array(
					'ID'           => TranslationProxy_Service::get_wpml_translator_id( $translation_service->id ),
					'display_name' => __( 'First available', 'sitepress' ),
					'service'      => $translation_service_name
				);
			} elseif ( in_array( $translation_service_id, $services ) && $is_service_authenticated ) {
				$lang_status = TranslationProxy_Translator::get_language_pairs();
				if ( empty( $lang_status ) ) {
					$lang_status = array();
			}
				foreach ( (array) $lang_status as $language_pair ) {
				if ( $from && $from != $language_pair[ 'from' ] ) {
					continue;
				}
				if ( $to && $to != $language_pair[ 'to' ] ) {
					continue;
				}

				if ( !empty( $language_pair[ 'translators' ] ) ) {
					if ( 1 < count( $language_pair[ 'translators' ] ) ) {
						$translators[ ] = (object) array(
									'ID'           => TranslationProxy_Service::get_wpml_translator_id( $translation_service->id ),
									'display_name' => __( 'First available', 'sitepress' ),
									'service'      => $translation_service_name
						);
					}
					foreach ( $language_pair[ 'translators' ] as $tr ) {
						if ( !isset( $_icl_translators[ $tr[ 'id' ] ] ) ) {
							$translators[ ] = $_icl_translators[ $tr[ 'id' ] ] = (object) array(
										'ID'           => TranslationProxy_Service::get_wpml_translator_id( $translation_service->id, $tr[ 'id' ] ),
									'display_name' => $tr[ 'nickname' ],
										'service'      => $translation_service_name
							);
						}
					}
				}
			}
		}

		if ( in_array( 'local', $services ) ) {
			$translators[ ] = (object) array(
					'ID'           => 0,
					'display_name' => __( 'First available', 'sitepress' ),
			);
			$translators    = array_merge( $translators, self::get_blog_translators( array( 'from' => $from, 'to' => $to ) ) );
		}
		$translators = apply_filters( 'wpml_tm_translators_list', $translators );

			$dropdown .= '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" ' . ( $disabled ? 'disabled="disabled"' : '' ) . '>';

			if ( $default_name ) {
				$dropdown_selected = selected( $selected, false, false );
				$dropdown .= '<option value="" ' . $dropdown_selected . '>';
				$dropdown .= esc_html( $default_name );
				$dropdown .= '</option>';
	}

			foreach ( $translators as $t ) {
				if ($local_only && isset($t->service) ) {
					continue;
				}
				$current_translator = $t->ID;

				$dropdown_selected = selected( $selected, $current_translator, false );
				$dropdown .= '<option value="' . $current_translator . '" ' . $dropdown_selected . '>';
				$dropdown .= esc_html( $t->display_name );
				if ( $show_service ) {
					$dropdown .= ' (';
					$dropdown .= isset( $t->service ) ? $t->service : __( 'Local', 'sitepress' );
					$dropdown .= ')';
				}
				$dropdown .= '</option>';
			}
			$dropdown .= '</select>';
		} catch ( TranslationProxy_Api_Error $ex ) {
			$dropdown .= __( 'Translation Proxy error', 'sitepress' ) . ': ' . $ex->getMessage();
		} catch ( Exception $ex ) {
			$dropdown .= __( 'Error', 'sitepress' ) . ': ' . $ex->getMessage();
		}

		if ( $echo ) {
			echo $dropdown;
		}

		return $dropdown;
	}

	/* HOOKS */
	/* ******************************************************************************************** */
	function save_post_actions( $post_id, $post, $force_set_status = false )
	{
		global $wpdb, $sitepress, $current_user;

		// skip revisions, auto-drafts and autosave
		if ( $post->post_type == 'revision' || $post->post_status == 'auto-draft' || isset( $_POST[ 'autosave' ] ) ) {
			return;
		}

		if ( isset( $_POST[ 'icl_trid' ] ) && is_numeric($_POST['icl_trid']) ) {
			$trid = $_POST['icl_trid'];
		} else {
			$trid = $sitepress->get_element_trid( $post_id, 'post_' . $post->post_type );
		}


		// set trid and lang code if front-end translation creating
		$trid = apply_filters( 'wpml_tm_save_post_trid_value', isset( $trid ) ? $trid : '', $post_id );
		$lang = apply_filters( 'wpml_tm_save_post_lang_value', isset( $lang ) ? $lang : '', $post_id );

		// is this the original document?
		$is_original = false;
		if ( !empty( $trid ) ) {
			$is_original = $wpdb->get_var( $wpdb->prepare( "SELECT source_language_code IS NULL FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND trid=%d", $post_id, $trid ) );
		}

		// when a manual translation is added/edited make sure to update translation tables
		if ( !empty( $trid ) && !$is_original ) {

			if ( ( !isset( $lang ) || !$lang ) && isset( $_POST[ 'icl_post_language' ] ) && !empty( $_POST[ 'icl_post_language' ] ) ) {
				$lang = $_POST[ 'icl_post_language' ];
			}

			$res = $wpdb->get_row( $wpdb->prepare( "
			 SELECT element_id, language_code FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND source_language_code IS NULL
		 ", $trid ) );
			if ( $res ) {
				$original_post_id = $res->element_id;
				$from_lang        = $res->language_code;
				$original_post    = get_post( $original_post_id );
				$md5              = $this->post_md5( $original_post );

				$translation_id_prepared = $wpdb->prepare( "SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND language_code=%s", $trid, $lang );
				$translation_id          = $wpdb->get_var( $translation_id_prepared );

				get_currentuserinfo();
				$user_id = $current_user->ID;


				if ($lang) {
				if ( !$this->is_translator( $user_id, array( 'lang_from' => $from_lang, 'lang_to' => $lang ) ) ) {
					$this->add_translator( $user_id, array( $from_lang => array( $lang => 1 ) ) );
				}
				}

				if ( $translation_id ) {
					$translation_package = $this->create_translation_package( $original_post_id );

					list( $rid, $update ) = $this->update_translation_status( array(
																				   'translation_id'      => $translation_id,
																				   'status'              => isset( $force_set_status ) && $force_set_status > 0 ? $force_set_status : ICL_TM_COMPLETE,
																				   'translator_id'       => $user_id,
																				   'needs_update'        => 0,
																				   'md5'                 => $md5,
																				   'translation_service' => 'local',
																				   'translation_package' => serialize( $translation_package )
																			  ) );
					if ( !$update ) {
						$job_id = $this->add_translation_job( $rid, $user_id, $translation_package );
					} else {
						$job_id_sql      = "SELECT MAX(job_id) FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d GROUP BY rid";
						$job_id_prepared = $wpdb->prepare( $job_id_sql, $rid );
						$job_id          = $wpdb->get_var( $job_id_prepared );
					}

					// saving the translation
					$this->save_job_fields_from_post( $job_id, $post );
				}
			}

		}

		// if this is an original post - compute md5 hash and mark for update if neded
		if ( !empty( $trid ) && empty( $_POST[ 'icl_minor_edit' ] ) ) {

			$is_original  = false;
			$translations = $sitepress->get_element_translations( $trid, 'post_' . $post->post_type );

			foreach ( $translations as $translation ) {
				if ( $translation->original == 1 && $translation->element_id == $post_id ) {
					$is_original = true;
					break;
				}
			}

			if ( $is_original ) {
				$md5 = $this->post_md5( $post_id );

				foreach ( $translations as $translation ) {
					if ( !$translation->original ) {
						$emd5_sql      = "SELECT md5 FROM {$wpdb->prefix}icl_translation_status WHERE translation_id = %d";
						$emd5_prepared = $wpdb->prepare( $emd5_sql, $translation->translation_id );
						$emd5          = $wpdb->get_var( $emd5_prepared );

						if ( $md5 != $emd5 ) {

							$translation_package = $this->create_translation_package( $post_id );

							$data                      = array(
																						   'translation_id'      => $translation->translation_id,
																						   'needs_update'        => 1,
																						   'md5'                 => $md5,
																						   'translation_package' => serialize( $translation_package )
							);
							$update_translation_status = $this->update_translation_status( $data );
							$rid = $update_translation_status[0];

							// update

							$translator_id_prepared = $wpdb->prepare( "SELECT translator_id FROM {$wpdb->prefix}icl_translation_status WHERE translation_id = %d", $translation->translation_id );
							$translator_id          = $wpdb->get_var( $translator_id_prepared );
							$job_id                 = $this->add_translation_job( $rid, $translator_id, $translation_package );

							// updating a post that's being translated - update fields in icl_translate
							if ( false === $job_id ) {
								$job_id_sql      = "SELECT MAX(job_id) FROM {$wpdb->prefix}icl_translate_job WHERE rid = %d";
								$job_id_prepared = $wpdb->prepare( $job_id_sql, $rid );
								$job_id          = $wpdb->get_var( $job_id_prepared );
								if ( $job_id ) {
									$job = $this->get_translation_job( $job_id );

									if ( $job ) {
										foreach ( $job->elements as $element ) {
											unset( $field_data );
											$_taxs_ids = false;
											switch ( $element->field_type ) {
												case 'title':
													$field_data = $this->encode_field_data( $post->post_title, $element->field_format );
													break;
												case 'body':
													$field_data = $this->encode_field_data( $post->post_content, $element->field_format );
													break;
												case 'excerpt':
													$field_data = $this->encode_field_data( $post->post_excerpt, $element->field_format );
													break;
												default:
													if ( false !== strpos( $element->field_type, 'field-' ) && !empty( $this->settings[ 'custom_fields_translation' ] ) ) {
														$cf_name = preg_replace( '#^field-#', '', $element->field_type );
														if ( isset( $this->settings[ 'custom_fields_translation' ][ $cf_name ] ) ) {
															$field_data = get_post_meta( $post->ID, $cf_name, 1 );
															$field_data = $this->encode_field_data( $field_data, $element->field_format );
														}
															}
														}

											if ( isset( $field_data ) && $field_data != $element->field_data ) {
												$wpdb->update( $wpdb->prefix . 'icl_translate', array( 'field_data' => $field_data ), array( 'tid' => $element->tid ) );
												}

											}
										}

								}

							}

						}
					}
				}
			}
		}

		// sync copies/duplicates
		$duplicates = $this->get_duplicates( $post_id );
		static $duplicated_post_ids;
		if ( !isset( $duplicated_post_ids ) ) {
			$duplicated_post_ids = array();
		}
		foreach ( $duplicates as $lang => $_pid ) {
			// Avoid infinite recursions
			if ( !in_array( $post_id . '|' . $lang, $duplicated_post_ids ) ) {
				$duplicated_post_ids[ ] = $post_id . '|' . $lang;
				$this->make_duplicate( $post_id, $lang );
			}
		}
	}

	function make_duplicates( $data )
	{
		foreach ( $data[ 'iclpost' ] as $master_post_id ) {
			foreach ( $data[ 'duplicate_to' ] as $lang => $one ) {
				$this->make_duplicate( $master_post_id, $lang );
			}
		}
	}

	function make_duplicate( $master_post_id, $lang ) {
        global $sitepress;

		return $sitepress->make_duplicate($master_post_id, $lang);
	}

	function make_duplicates_all( $master_post_id )
	{
		global $sitepress;

		$master_post               = get_post( $master_post_id );
		if($master_post->post_status == 'auto-draft' || $master_post->post_type == 'revision') {
			return;
		}

		$language_details_original = $sitepress->get_element_language_details( $master_post_id, 'post_' . $master_post->post_type );

		if(!$language_details_original) return;

		$data[ 'iclpost' ] = array( $master_post_id );
		foreach ( $sitepress->get_active_languages() as $lang => $details ) {
			if ( $lang != $language_details_original->language_code ) {
				$data[ 'duplicate_to' ][ $lang ] = 1;
			}
		}

		$this->make_duplicates( $data );
	}

	function reset_duplicate_flag( $post_id )
	{
		global $sitepress;

		$post = get_post( $post_id );

		$trid         = $sitepress->get_element_trid( $post_id, 'post_' . $post->post_type );
		$translations = $sitepress->get_element_translations( $trid, 'post_' . $post->post_type );

		foreach ( $translations as $tr ) {
			if ( $tr->element_id == $post_id ) {
				$this->update_translation_status( array(
													   'translation_id' => $tr->translation_id,
													   'status'         => ICL_TM_COMPLETE
												  ) );
			}
		}

		delete_post_meta( $post_id, '_icl_lang_duplicate_of' );


	}

	function set_duplicate( $post_id ) {
		global $wpml_post_translations;

		$master_post_id = $wpml_post_translations->get_original_element ( $post_id );
		$this_language  = $wpml_post_translations->get_element_lang_code ( $post_id );
		$new_id = 0;
		if ( $master_post_id && $this_language ) {
			$new_id = $this->make_duplicate ( $master_post_id, $this_language );
		}

		return $new_id;
	}

    function get_duplicates( $master_post_id ) {
        global $sitepress;

        return $sitepress->get_duplicates ( $master_post_id );
    }

	function duplication_delete_comment( $comment_id )
	{
		global $wpdb;
		static $_avoid_8_loop;

		if ( isset( $_avoid_8_loop ) ) {
			return;
		}
		$_avoid_8_loop = true;

		$original_comment = get_comment_meta( $comment_id, '_icl_duplicate_of', true );
		if ( $original_comment ) {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $original_comment ) );
			$duplicates = array( $original_comment ) + array_diff( $duplicates, array( $comment_id ) );
			foreach ( $duplicates as $dup ) {
				wp_delete_comment( $dup );
			}
		} else {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $comment_id ) );
			if ( $duplicates ) {
				foreach ( $duplicates as $dup ) {
					wp_delete_comment( $dup );
				}
			}
		}

		unset( $_avoid_8_loop );
	}

	function duplication_edit_comment( $comment_id )
	{
		global $wpdb;

		$comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d", $comment_id ), ARRAY_A );
		unset( $comment[ 'comment_ID' ], $comment[ 'comment_post_ID' ] );

		$comment_meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id=%d AND meta_key <> '_icl_duplicate_of'", $comment_id ) );

		$original_comment = get_comment_meta( $comment_id, '_icl_duplicate_of', true );
		if ( $original_comment ) {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $original_comment ) );
			$duplicates = array( $original_comment ) + array_diff( $duplicates, array( $comment_id ) );
		} else {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $comment_id ) );
		}

		if ( !empty( $duplicates ) ) {
			foreach ( $duplicates as $dup ) {

				$wpdb->update( $wpdb->comments, $comment, array( 'comment_ID' => $dup ) );

				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id=%d AND meta_key <> '_icl_duplicate_of'", $dup ) );

				if ( $comment_meta ) {
					foreach ( $comment_meta as $key => $value ) {
						update_comment_meta( $dup, $key, $value );
					}
				}

			}
		}


	}

	function duplication_status_comment( $comment_id, $comment_status )
	{
		global $wpdb;

		static $_avoid_8_loop;

		if ( isset( $_avoid_8_loop ) ) {
			return;
		}
		$_avoid_8_loop = true;


		$original_comment = get_comment_meta( $comment_id, '_icl_duplicate_of', true );
		if ( $original_comment ) {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $original_comment ) );
			$duplicates = array( $original_comment ) + array_diff( $duplicates, array( $comment_id ) );
		} else {
			$duplicates = $wpdb->get_col( $wpdb->prepare( "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_icl_duplicate_of' AND meta_value=%d", $comment_id ) );
		}

		if ( !empty( $duplicates ) ) {
			foreach ( $duplicates as $duplicate ) {
				wp_set_comment_status( $duplicate, $comment_status );
			}
		}

		unset( $_avoid_8_loop );


	}

	function duplication_insert_comment( $comment_id )
	{
		global $wpdb, $sitepress;

		$comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID=%d", $comment_id ), ARRAY_A );

		// loop duplicate posts, add new comment
		$post_id = $comment[ 'comment_post_ID' ];

		// if this is a duplicate post
		$duplicate_of = get_post_meta( $post_id, '_icl_lang_duplicate_of', true );
		if ( $duplicate_of ) {
			$post_duplicates = $this->get_duplicates( $duplicate_of );
			$_lang           = $wpdb->get_var( $wpdb->prepare( "SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='comment' AND element_id=%d", $comment_id ) );
			unset( $post_duplicates[ $_lang ] );
			$_post                          = get_post( $duplicate_of );
			$_orig_lang                     = $sitepress->get_language_for_element( $duplicate_of, 'post_' . $_post->post_type );
			$post_duplicates[ $_orig_lang ] = $duplicate_of;
		} else {
			$post_duplicates = $this->get_duplicates( $post_id );
		}

		unset( $comment[ 'comment_ID' ], $comment[ 'comment_post_ID' ] );

		foreach ( $post_duplicates as $lang => $dup_id ) {
			$comment[ 'comment_post_ID' ] = $dup_id;

			if ( $comment[ 'comment_parent' ] ) {
				$comment[ 'comment_parent' ] = icl_object_id( $comment[ 'comment_parent' ], 'comment', false, $lang );
			}


			$wpdb->insert( $wpdb->comments, $comment );

			$dup_comment_id = $wpdb->insert_id;

			update_comment_meta( $dup_comment_id, '_icl_duplicate_of', $comment_id );

			// comment meta
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id=%d", $comment_id ) );
			foreach ( $meta as $key => $val ) {
				$wpdb->insert( $wpdb->commentmeta, array(
														'comment_id' => $dup_comment_id,
														'meta_key'   => $key,
														'meta_value' => $val
												   ) );
			}

			$original_comment_tr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s", $comment_id, 'comment' ) );

			$comment_translation = array(
				'element_type'  => 'comment',
				'element_id'    => $dup_comment_id,
				'trid'          => $original_comment_tr->trid,
				'language_code' => $lang,
				/*'source_language_code'  => $original_comment_tr->language_code */
			);

			$wpdb->insert( $wpdb->prefix . 'icl_translations', $comment_translation );

		}


	}

	function delete_post_actions($post_id){
		global $wpdb;
        $post_type = $wpdb->get_var( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $post_id ) );
		if(!empty($post_type)){
			$translation_id = $wpdb->get_var($wpdb->prepare("SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s", $post_id, 'post_' . $post_type));
			if($translation_id){
				$rid = $wpdb->get_var($wpdb->prepare("SELECT rid FROM {$wpdb->prefix}icl_translation_status WHERE translation_id=%d", $translation_id));
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translation_status WHERE translation_id=%d", $translation_id));
				if($rid){
					$jobs = $wpdb->get_col($wpdb->prepare("SELECT job_id FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d", $rid));
					$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d", $rid));
					if(!empty($jobs)){
                        $wpdb->query(
                            "DELETE FROM {$wpdb->prefix}icl_translate WHERE job_id IN (" . wpml_prepare_in(
                                $jobs,
                                '%d'
                            ) . ")"
                        );
					}
				}
			}
		}
	}

	/**
	 * This action is hooked to every edit of a term. It ensures that every job,
	 * in which this term is an original is updated with the new term name.
	 *
	 * Term ID of the edited term, the name of which is going to be updated in all jobs it is translated in.
	 * @param $tid Int
	 * Term Taxonomy ID of that term
	 * @param $ttid Int
	 */
	function edit_term( $tid, $ttid ) {
		global $wpdb;

		$term_name         = $wpdb->get_var(
			$wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = %d", $tid )
						);
		$encoded_term_name = base64_encode( $term_name );

		$t = $wpdb->prefix . 'icl_translate';

		$update_original_terms_in_jobs_query = $wpdb->prepare( "
			UPDATE {$t}
			SET field_data = %s
			WHERE
				field_type = CONCAT('t_', %s)",
		                                                       $encoded_term_name, $ttid );

		$wpdb->get_results( $update_original_terms_in_jobs_query );
					}

	/* TRANSLATIONS */
	/* ******************************************************************************************** */
	/**
	* calculate post md5
	*
	* @param object|int $post
	* @return string
	*
	* @todo full support for custom posts and custom taxonomies
	*/
	function post_md5($post){

		//TODO: [WPML 3.2] Make it work with PackageTranslation: this is not the right way anymore
		if (isset($post->external_type) && $post->external_type) {

			$md5str = '';

			foreach ($post->string_data as $key => $value) {
				$md5str .= $key . $value;
			}

		} else {

			$post_tags = $post_categories = $custom_fields_values = array();

			if(is_numeric($post)){
				$post = get_post($post);
			}

			foreach(wp_get_object_terms($post->ID, 'post_tag') as $tag){
				$post_tags[] = $tag->name;
			}
			if(is_array($post_tags)){
				sort($post_tags, SORT_STRING);
			}
			foreach(wp_get_object_terms($post->ID, 'category') as $cat){
				$post_categories[] = $cat->name;
			}
			if(is_array($post_categories)){
				sort($post_categories, SORT_STRING);
			}

			global $wpdb, $sitepress_settings;
			// get custom taxonomies
            $taxonomies = $wpdb->get_col(
                $wpdb->prepare(
                    "
				SELECT DISTINCT tx.taxonomy
				FROM {$wpdb->term_taxonomy} tx JOIN {$wpdb->term_relationships} tr ON tx.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id =%d ",
                    $post->ID
                )
            );

			sort($taxonomies, SORT_STRING);
			foreach($taxonomies as $t){
				if(taxonomy_exists($t)){
					if(@intval($sitepress_settings['taxonomies_sync_option'][$t]) == 1){
						$taxs = array();
						foreach(wp_get_object_terms($post->ID, $t) as $trm){
							$taxs[] = $trm->name;
						}
						if($taxs){
							sort($taxs,SORT_STRING);
							$all_taxs[] = '['.$t.']:'.join(',',$taxs);
						}
					}
				}
			}

			$custom_fields_values = array();
			if ( is_array( $this->settings['custom_fields_translation'] ) ) {
				foreach ( $this->settings['custom_fields_translation'] as $cf => $op ) {
					if ( $op == 2 || $op == 1 ) {
						$value = get_post_meta( $post->ID, $cf, true );
						if ( !is_array( $value ) && !is_object( $value ) ) {
							$custom_fields_values[] = $value;
						}
					}
				}
			}

			$md5str =
				$post->post_title . ';' .
				$post->post_content . ';' .
				join(',',$post_tags).';' .
				join(',',$post_categories) . ';' .
				join(',', $custom_fields_values);

			if(!empty($all_taxs)){
				$md5str .= ';' . join(';', $all_taxs);
			}

			if ( icl_get_setting( 'translated_document_page_url' ) === 'translate' ) {
				$md5str .=  $post->post_name . ';';
			}


		}

		$md5 = md5($md5str);

		return $md5;
	}

	function get_element_translation($element_id, $language, $element_type='post_post'){
		global $wpdb, $sitepress;
		$trid = $sitepress->get_element_trid($element_id, $element_type);
		$translation = array();
		if($trid){
			$translation = $wpdb->get_row($wpdb->prepare("
				SELECT *
				FROM {$wpdb->prefix}icl_translations tr
				JOIN {$wpdb->prefix}icl_translation_status ts ON tr.translation_id = ts.translation_id
				WHERE tr.trid=%d AND tr.language_code= %s
			", $trid, $language));
		}
		return $translation;
	}

	function get_element_translations($element_id, $element_type='post_post', $service = false){
		global $wpdb, $sitepress;
		$trid = $sitepress->get_element_trid($element_id, $element_type);
		$translations = array();
		if($trid){
			$service =  $service ? $wpdb->prepare(" AND translation_service = %s ", $service ) : '';
			$translations = $wpdb->get_results($wpdb->prepare("
				SELECT *
				FROM {$wpdb->prefix}icl_translations tr
				JOIN {$wpdb->prefix}icl_translation_status ts ON tr.translation_id = ts.translation_id
				WHERE tr.trid=%d {$service}
			", $trid));
			foreach($translations as $k=>$v){
				$translations[$v->language_code] = $v;
				unset($translations[$k]);
			}
		}
		return $translations;
	}

	/**
	 * returns icon file name according to status code
	 *
	 * @param int $status
	 * @param int $needs_update
	 *
	 * @return string
	 */
	public function status2img_filename($status, $needs_update = 0){
		if($needs_update){
			$img_file = 'needs-update.png';
		}else{
			switch($status){
				case ICL_TM_NOT_TRANSLATED: $img_file = 'not-translated.png'; break;
				case ICL_TM_WAITING_FOR_TRANSLATOR: $img_file = 'in-progress.png'; break;
				case ICL_TM_IN_PROGRESS: $img_file = 'in-progress.png'; break;
                case ICL_TM_IN_BASKET: $img_file = 'in-basket.png'; break;
				case ICL_TM_NEEDS_UPDATE: $img_file = 'needs-update.png'; break;
				case ICL_TM_DUPLICATE: $img_file = 'copy.png'; break;
				case ICL_TM_COMPLETE: $img_file = 'complete.png'; break;
				default: $img_file = '';
			}
		}
		return $img_file;
	}

    public static function status2text($status){
		switch($status){
			case ICL_TM_NOT_TRANSLATED: $text = __('Not translated', 'sitepress'); break;
			case ICL_TM_WAITING_FOR_TRANSLATOR: $text = __('Waiting for translator', 'sitepress'); break;
			case ICL_TM_IN_PROGRESS: $text = __('In progress', 'sitepress'); break;
			case ICL_TM_NEEDS_UPDATE: $text = __('Needs update', 'sitepress'); break;
			case ICL_TM_DUPLICATE: $text = __('Duplicate', 'sitepress'); break;
			case ICL_TM_COMPLETE: $text = __('Complete', 'sitepress'); break;
			default: $text = '';
		}
		return $text;
	}

	public static function decode_field_data($data, $format){
		if($format == 'base64'){
			$data = base64_decode($data);
		}elseif($format == 'csv_base64'){
			$exp = explode(',', $data);
			foreach($exp as $k=>$e){
				$exp[$k] = base64_decode(trim($e,'"'));
			}
			$data = $exp;
		}
		return $data;
	}

	public function encode_field_data($data, $format){
		if($format == 'base64'){
			$data = base64_encode($data);
		}elseif($format == 'csv_base64'){
			$exp = (array) $data;
			foreach($exp as $k=>$e){
				$exp[$k] = '"' . base64_encode(trim($e)) . '"';
			}
			$data = join(',', $exp);
		}
		return $data;
	}

	/**
	 * create translation package
	 *
	 * @param object|int $post
	 *
	 * @return array
	 */
	function create_translation_package($post){
		global $sitepress, $sitepress_settings;

	    if ( empty( $this->settings ) ) {
			$this->init();
	    }

		$package = array();

		if(is_numeric($post)){
			$post = get_post($post);
		}

		$post_type = $post->post_type;
		if (apply_filters('wpml_is_external', false, $post)) {

			foreach ($post->string_data as $key => $value) {
				$package['contents'][$key] = array(
					'translate' => 1,
					'data'      => $this->encode_field_data($value, 'base64'),
					'format'    => 'base64'
				);
			}

			$package['contents']['original_id'] = array(
				'translate' => 0,
				'data'      => $post->post_id,
			);
		} else {
			$home_url = get_home_url();
			if( $post_type =='page'){
				$package['url'] = htmlentities( $home_url . '?page_id=' . ($post->ID));
			}else{
				$package['url'] = htmlentities( $home_url . '?p=' . ($post->ID));
			}

			$package['contents']['title'] = array(
				'translate' => 1,
				'data'      => $this->encode_field_data($post->post_title, 'base64'),
				'format'    => 'base64'
			);

			if($sitepress_settings['translated_document_page_url'] == 'translate'){
				$package['contents']['URL'] = array(
					'translate' => 1,
					'data'      => $this->encode_field_data($post->post_name, 'base64'),
					'format'    => 'base64'
				);
			}

			$package['contents']['body'] = array(
				'translate' => 1,
				'data'      => $this->encode_field_data($post->post_content, 'base64'),
				'format'    => 'base64'
			);

			if(!empty($post->post_excerpt)){
				$package['contents']['excerpt'] = array(
					'translate' => 1,
					'data'      => base64_encode($post->post_excerpt),
					'format'    => 'base64'
				);
			}

			$package['contents']['original_id'] = array(
				'translate' => 0,
				'data'      => $post->ID
			);

			if(!empty($this->settings['custom_fields_translation']))
			foreach($this->settings['custom_fields_translation'] as $cf => $op){
				if ($op == 2) { // translate

					/* */
					$custom_fields_value = get_post_meta($post->ID, $cf, true);
					if ($custom_fields_value != '' && is_scalar($custom_fields_value)) {
						$package['contents']['field-'.$cf] = array(
							'translate' => 1,
							'data' => $this->encode_field_data($custom_fields_value, 'base64'),
							'format' => 'base64'
						);
						$package['contents']['field-'.$cf.'-name'] = array(
							'translate' => 0,
							'data' => $cf
						);
						$package['contents']['field-'.$cf.'-type'] = array(
							'translate' => 0,
							'data' => 'custom_field'
						);
					}
				}
			}

			foreach((array)$sitepress->get_translatable_taxonomies(true, $post_type ) as $taxonomy){
				$terms = get_the_terms( $post->ID , $taxonomy );
				if(!empty($terms)){
					foreach($terms as $term){
						$package[ 'contents' ][ 't_' . $term->term_taxonomy_id ] = array(
							'translate' => 1,
							'data'      => $this->encode_field_data( $term->name, 'csv_base64' ),
							'format'    => 'csv_base64'
						);
					}
					}
					}
					}

		return $package;
	}

	function get_messages() {
		return $this->messages_by_type(false);
				}

	function messages_by_type( $type ) {
		$messages = $this->messages;

		$result = false;
		foreach ( $messages as $message ) {
			if ($type === false || ( !empty( $message[ 'type' ] ) && $message[ 'type' ] == $type) ) {
				$result[ ] = $message;
			}
		}

		return $result;
	}

	function add_message( $message ) {
		$this->messages[ ] = $message;
	}

	/**
	 * add/update icl_translation_status record
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	function update_translation_status( $data ) {
		global $wpdb;
		if ( ! isset( $data[ 'translation_id' ] ) ) {
			return array( false, false );
		}
		$rid           = $wpdb->get_var( $wpdb->prepare( "	SELECT rid
															FROM {$wpdb->prefix}icl_translation_status
															WHERE translation_id=%d",
		                                                 $data[ 'translation_id' ] ) );
		if ( $rid ) {
			$data_where = array( 'rid' => $rid );
			$wpdb->update( $wpdb->prefix . 'icl_translation_status', $data, $data_where );

			$update = true;
		} else {
			$wpdb->insert( $wpdb->prefix . 'icl_translation_status', $data );
			$rid    = $wpdb->insert_id;
			$update = false;
		}
		$data[ 'rid' ] = $rid;

		do_action( 'wpml_updated_translation_status', $data );

		return array( $rid, $update );
	}

	/* TRANSLATION JOBS */
	/* ******************************************************************************************** */

	/**
	 * Sends all jobs from basket in batch mode to translation proxy
	 *
	 * @param array $data POST data
	 *
	 * @return bool	Returns false in case of errors (read from TranslationManagement::get_messages('error') to get errors details)
	 */
	function send_all_jobs( $data ) {
		if ( ! isset( $data ) || ! is_array( $data ) ) {
			return false;
		}

		// 1. get wp_option with basket

		$basket_name = TranslationProxy_Basket::get_basket_name();
		if ( !$basket_name ) {
			$basket_name = isset( $data[ 'basket_name' ] ) ? $data[ 'basket_name' ] : false;
			if ( $basket_name ) {
				TranslationProxy_Basket::set_basket_name( $basket_name );
	}
		}

		$this->set_translation_jobs_basket( $data );

		// check if we have local and remote translators

		$translators = isset( $data[ 'translators' ] ) ? $data[ 'translators' ] : array();

		// find all target languages for remote service (it is required to create proper batch in translation proxy)

		$this->set_remote_target_languages( $translators );

		// save information about target languages for remote service
		TranslationProxy_Basket::set_remote_target_languages($this->remote_target_languages);

		$basket_items_types = TranslationProxy_Basket::get_basket_items_types();
		foreach ( $basket_items_types as $item_type_name => $item_type ) {
			$type_basket_items = array();
			if ( isset( $this->translation_jobs_basket[ $item_type_name ] ) ) {
				$type_basket_items = $this->translation_jobs_basket[ $item_type_name ];
			}
			do_action( 'wpml_tm_send_' . $item_type_name . '_jobs', $item_type_name, $item_type, $type_basket_items, $translators, $basket_name );
		}

		// check if there were no errors
		return !$this->messages_by_type('error');
	}

	function send_jobs($data){
		global $wpdb, $sitepress;

		if(!isset($data['tr_action']) && isset($data['translate_to'])){ //adapt new format
			$data['tr_action'] = $data['translate_to'];
			unset($data['translate_to']);
		}

		if ( isset( $data[ 'iclpost' ] ) ) { //adapt new format
			$data[ 'posts_to_translate' ] = $data[ 'iclpost' ];
			unset( $data[ 'iclpost' ] );
		}
		if ( isset( $data[ 'post' ] ) ) { //adapt new format
			$data[ 'posts_to_translate' ] = $data[ 'post' ];
			unset( $data[ 'post' ] );
		}

		$batch_name = isset($data['batch_name']) ? $data['batch_name'] : false;

		$translate_from = TranslationProxy_Basket::get_source_language();
		$data_default = array(
				'translate_from' => $translate_from
		);
		extract($data_default);
		extract($data, EXTR_OVERWRITE);

		// no language selected ?
		if(!isset($tr_action) || empty($tr_action)){
			$this->dashboard_select = $data; // prepopulate dashboard
			return false;
		}
		// no post selected ?
		if ( !isset( $posts_to_translate ) || empty( $posts_to_translate ) ) {
			$this->dashboard_select = $data; // pre-populate dashboard
			return false;
		}

		$selected_posts       = $posts_to_translate;
		$selected_translators = isset( $translators ) ? $translators : array();
		$selected_languages = $tr_action;
		$job_ids = array();

		$element_type_prefix = 'post';
		if ( isset( $data[ 'element_type_prefix' ] ) ) {
			$element_type_prefix = $data[ 'element_type_prefix' ];
		}

		foreach($selected_posts as $post_id){
			$post = $this->get_post( $post_id, $element_type_prefix );
			if(!$post) {
				continue;
			}

			$element_type      = $element_type_prefix .'_' . $post->post_type;

			$post_trid         = $sitepress->get_element_trid( $post_id, $element_type );
			$post_translations = $sitepress->get_element_translations( $post_trid, $element_type );
			$md5 = $this->post_md5($post);

			$translation_package = $this->create_translation_package($post);

			foreach($selected_languages as $lang => $action){

				// making this a duplicate?
				if($action == 2){
					// don't send documents that are in progress
					$current_translation_status = $this->get_element_translation( $post_id, $lang, $element_type );
					if ( $current_translation_status && $current_translation_status->status == ICL_TM_IN_PROGRESS ) {
						continue;
					}

					$job_ids[] = $this->make_duplicate($post_id, $lang);
				}elseif($action == 1){

					if(empty($post_translations[$lang])){
						$translation_id = $sitepress->set_element_language_details( null, $element_type, $post_trid, $lang, $translate_from );
					}else{
						$translation_id = $post_translations[$lang]->translation_id;
					}

					// don't send documents that are in progress
					// don't send documents that are already translated and don't need update
					$current_translation_status = $this->get_element_translation( $post_id, $lang, $element_type );

					if ( $current_translation_status && $current_translation_status->status == ICL_TM_IN_PROGRESS ) {
						continue;
					}

					$_status = ICL_TM_WAITING_FOR_TRANSLATOR;

					if ( isset($selected_translators[$lang]) ) {
						$translator = $selected_translators[$lang];
					} else {
						$translator = get_current_user_id(); // returns current user id or 0 if user not logged in
					}
					$translation_data = TranslationProxy_Service::get_translator_data_from_wpml($translator);

					$translation_service = $translation_data['translation_service'];

					$translator_id = $translation_data['translator_id'];

					// set as default translator
					if($translator_id > 0){
						$this->set_default_translator($translator_id, $translate_from, $lang, $translation_service);
					}

					// add translation_status record
					$data = array(
						'translation_id'        => $translation_id,
						'status'                => $_status,
						'translator_id'         => $translator_id,
						'needs_update'          => 0,
						'md5'                   => $md5,
						'translation_service'   => $translation_service,
							'translation_package' => serialize( $translation_package ),
							'batch_id'            => $this->update_translation_batch( $batch_name, $translation_service ),
					);

					$_prevstate = $wpdb->get_row($wpdb->prepare("
						SELECT status, translator_id, needs_update, md5, translation_service, translation_package, timestamp, links_fixed
						FROM {$wpdb->prefix}icl_translation_status
						WHERE translation_id = %d
					", $translation_id), ARRAY_A);
					if ( $_prevstate ) {
						$data['_prevstate'] = serialize($_prevstate);
					}

					$update_translation_status = $this->update_translation_status( $data );
					$rid = $update_translation_status[0]; //__ adds or updates row in icl_translation_status,

					$job_id     = $this->add_translation_job( $rid, $translator_id, $translation_package );
					$job_ids[ ] = $job_id;

					if ( $translation_service != 'local' ) {
						global $ICL_Pro_Translation;
						$sent = $ICL_Pro_Translation->send_post( $post, array( $lang ), $translator_id, $job_id );
						if(!$sent){
							$job_id = array_pop($job_ids);
							$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id));
							$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}icl_translate_job SET revision = NULL WHERE rid=%d ORDER BY job_id DESC LIMIT 1", $rid));
							$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translate WHERE job_id=%d", $job_id));
						}
					}
				} // if / else is making a duplicate
			}

		}

		TM_Notification::mail_queue();

		return $job_ids;
	}

	/**
	 * Adds a translation job record in icl_translate_job
	 *
	 * @param mixed $rid
	 * @param mixed $translator_id
	 * @param       $translation_package
	 *
	 * @return bool|int
	 */
	function add_translation_job($rid, $translator_id, $translation_package){
		global $wpdb, $current_user;
		get_currentuserinfo();
		    if ( empty( $this->settings ) ) {
					$this->init();
		    }

		if(!$current_user->ID){
			$manager_id = $wpdb->get_var($wpdb->prepare("SELECT manager_id FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d ORDER BY job_id DESC LIMIT 1", $rid));
		}else{
			$manager_id = $current_user->ID;
		}

		$translation_status = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}icl_translation_status WHERE rid=%d", $rid));

		// if we have a previous job_id for this rid mark it as the top (last) revision
		list($prev_job_id, $prev_job_translated) = $wpdb->get_row($wpdb->prepare("
					SELECT job_id, translated FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d AND revision IS NULL
		", $rid), ARRAY_N);
			if ( !is_null( $prev_job_id ) ) {

				if ( !$prev_job_translated ) {
	        // Job id needed to generate the xliff file
                return $prev_job_id;
				}

				$last_rev = $wpdb->get_var( $wpdb->prepare( "
				SELECT MAX(revision) AS rev FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d AND revision IS NOT NULL
			", $rid ) );
				$wpdb->update( $wpdb->prefix . 'icl_translate_job', array( 'revision' => $last_rev + 1 ), array( 'job_id' => $prev_job_id ) );

				$prev_job = $this->get_translation_job( $prev_job_id );

				if ( isset( $prev_job->original_doc_id ) ) {
					$original_post = get_post( $prev_job->original_doc_id );
					foreach ( $prev_job->elements as $element ) {
						$prev_translation[ $element->field_type ] = $element->field_data_translated;
						switch ( $element->field_type ) {
							case 'title':
								if ( self::decode_field_data( $element->field_data, $element->field_format ) == $original_post->post_title ) {
									//$unchanged[$element->field_type] = $element->field_data_translated;
									$unchanged[ $element->field_type ] = true;
								}
								break;
							case 'body':
								if ( self::decode_field_data( $element->field_data, $element->field_format ) == $original_post->post_content ) {
									//$unchanged[$element->field_type] = $element->field_data_translated;
									$unchanged[ $element->field_type ] = true;
								}
								break;
							case 'excerpt':
								if ( self::decode_field_data( $element->field_data, $element->field_format ) == $original_post->post_excerpt ) {
									//$unchanged[$element->field_type] = $element->field_data_translated;
									$unchanged[ $element->field_type ] = true;
								}
								break;
							default:
								if ( false !== strpos( $element->field_type, 'field-' ) && !empty( $this->settings[ 'custom_fields_translation' ] ) ) {
									$cf_name = preg_replace( '#^field-#', '', $element->field_type );
									if ( self::decode_field_data( $element->field_data, $element->field_format ) == get_post_meta( $prev_job->original_doc_id, $cf_name, 1 ) ) {
										//$unchanged[$element->field_type] = $element->field_data_translated;
										$unchanged[ $element->field_type ] = true;
									}
								} else {
									// taxonomies
									if ( strpos( $element->field_type, 't_', 0 ) ) {
										$ttid = substr( $element->field_type, 2 );
										$term_name = $wpdb->get_var(
											$wpdb->prepare( "SELECT name FROM {$wpdb->terms} WHERE term_id = (SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d LIMIT 1)",
											                $ttid )
										);
										if ( $element->field_data == $this->encode_field_data( $term_name,
										                                                       $element->field_format )
										) {
											$unchanged[ $element->field_type ] = true;
										}
									}
								}
						}
					}
				}
			}

	    $translate_job_insert_data = array(
			'rid' => $rid,
			'translator_id' => $translator_id,
			'translated'    => 0,
			'manager_id'    => $manager_id
	    );
	    $wpdb->insert($wpdb->prefix . 'icl_translate_job', $translate_job_insert_data );
		$job_id = $wpdb->insert_id;

		foreach($translation_package['contents'] as $field => $value){
			$job_translate = array(
				'job_id'            => $job_id,
				'content_id'        => 0,
				'field_type'        => $field,
				'field_format'      => isset($value['format'])?$value['format']:'',
				'field_translate'   => $value['translate'],
				'field_data'        => $value['data'],
				'field_data_translated' => isset($prev_translation[$field]) ? $prev_translation[$field] : '',
				'field_finished'    => 0
			);
			if(isset($unchanged[$field])){
				$job_translate['field_finished'] = 1;
			}

	        $wpdb->hide_errors();
			$wpdb->insert($wpdb->prefix . 'icl_translate', $job_translate);
		}

		if ( $translation_status->translation_service == 'local' ) {
			if ( $this->settings[ 'notification' ][ 'new-job' ] == ICL_TM_NOTIFICATION_IMMEDIATELY ) {
				if ( $job_id ) {
					$tn_notification = new TM_Notification();
					if ( empty( $translator_id ) ) {
						$tn_notification->new_job_any( $job_id );
					} else {
						$tn_notification->new_job_translator( $job_id, $translator_id );
					}
				}
			}
			do_action( 'wpml_added_local_translation_job', $job_id );
		}

		return $job_id;
	}

	function assign_translation_job($job_id, $translator_id, $service='local'){
		global $wpdb;

		// make sure TM is running
		if(empty($this->settings)){
			$this->init();
		}

        list( $prev_translator_id, $rid ) = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT translator_id, rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d",
                $job_id
            ),
            ARRAY_N
        );

        $assigned_correctly = $translator_id == $prev_translator_id;
        $assigned_correctly = apply_filters(
            'wpml_job_assigned_to_after_assignment',
            $assigned_correctly,
            $job_id,
            $translator_id,
            $service
        );

        if ( $assigned_correctly ) {
            return true;
        }

        require_once ICL_PLUGIN_PATH . '/inc/translation-management/tm-notification.class.php';
		$tn_notification = new TM_Notification();
		if($this->settings['notification']['resigned'] == ICL_TM_NOTIFICATION_IMMEDIATELY){
			if(!empty($prev_translator_id) && $prev_translator_id != $translator_id){
				if($job_id){
					$tn_notification->translator_removed($prev_translator_id, $job_id);
				}
			}
		}

		if($this->settings['notification']['new-job'] == ICL_TM_NOTIFICATION_IMMEDIATELY){
			if(empty($translator_id)){
				$tn_notification->new_job_any($job_id);
			}else{
				$tn_notification->new_job_translator($job_id, $translator_id);
			}
		}

		$data = array(
				'translator_id'       => $translator_id,
				'status'              => ICL_TM_WAITING_FOR_TRANSLATOR,
				'translation_service' => $service
		);
		$data_where = array( 'rid' => $rid );
		$wpdb->update( $wpdb->prefix . 'icl_translation_status', $data, $data_where );
		$wpdb->update($wpdb->prefix.'icl_translate_job', array('translator_id'=>$translator_id), array('job_id'=>$job_id));

		return true;
	}

    function get_translation_jobs( $args = array() ) {

        return apply_filters ( 'wpml_translation_jobs', array(), $args );
    }

	/**
	 * Clean orphan jobs in posts
	 *
	 * @param array $posts
	 */
	function cleanup_translation_jobs_cart_posts( $posts ) {
		if ( empty( $posts ) ) {
			return;
		}

		foreach ( $posts as $post_id => $post_data ) {
			if ( !get_post( $post_id ) ) {
				TranslationProxy_Basket::delete_item_from_basket( $post_id );
			}
		}
	}

	/**
	 * Incorporates posts in cart data with post title, post date, post notes,
	 * post type, post status
	 *
	 * @param array $posts
	 *
	 * @return boolean | array
	 */
	function get_translation_jobs_basket_posts( $posts ) {
		if ( empty( $posts ) ) {
			return false;
		}

		$this->cleanup_translation_jobs_cart_posts( $posts );

		global $sitepress;

		$posts_ids = array_keys( $posts );

		$args = array(
				'posts_per_page' => -1,
				'include'        => $posts_ids,
				'post_type'      => get_post_types(),
				'post_status'    => get_post_stati(), // All post statuses
		);

		$new_posts = get_posts( $args );

		$final_posts = array();

		foreach ( $new_posts as $post_data ) {
			// set post_id
			$final_posts[ $post_data->ID ] = false;
			// set post_title
			$final_posts[ $post_data->ID ][ 'post_title' ] = $post_data->post_title;
			// set post_date
			$final_posts[ $post_data->ID ][ 'post_date' ] = $post_data->post_date;
			// set post_notes
			$final_posts[ $post_data->ID ][ 'post_notes' ] = get_post_meta( $post_data->ID, '_icl_translator_note', true );;
			// set post_type
			$final_posts[ $post_data->ID ][ 'post_type' ] = $post_data->post_type;
			// set post_status
			$final_posts[ $post_data->ID ][ 'post_status' ] = $post_data->post_status;
			// set from_lang
			$final_posts[ $post_data->ID ][ 'from_lang' ]        = $posts[ $post_data->ID ][ 'from_lang' ];
			$final_posts[ $post_data->ID ][ 'from_lang_string' ] = ucfirst( $sitepress->get_display_language_name( $posts[ $post_data->ID ][ 'from_lang' ], $sitepress->get_admin_language() ) );
			// set to_langs
			$final_posts[ $post_data->ID ][ 'to_langs' ] = $posts[ $post_data->ID ][ 'to_langs' ];
			// set comma separated to_langs -> to_langs_string
			$language_names = array();
			foreach ( $final_posts[ $post_data->ID ][ 'to_langs' ] as $language_code => $value ) {
				$language_names[ ] = ucfirst( $sitepress->get_display_language_name( $language_code, $sitepress->get_admin_language() ) );
			}
			$final_posts[ $post_data->ID ][ 'to_langs_string' ] = implode( ", ", $language_names );
		}

		return $final_posts;
	}

	/**
	 * Incorporates strings in cart data
	 *
	 * @param array       $strings
	 * @param bool|string $source_language
	 *
	 * @return boolean | array
	 */
	function get_translation_jobs_basket_strings( $strings, $source_language = false ) {
		$final_strings = array();
		if ( class_exists( 'WPML_String_Translation' ) ) {
			global $sitepress;

			$source_language = $source_language ? $source_language : TranslationProxy_Basket::get_source_language();
			foreach ( $strings as $string_id => $data ) {
				if ( $source_language ) {
					// set post_id
					$final_strings[ $string_id ] = false;
					// set post_title
					$final_strings[ $string_id ][ 'post_title' ] = icl_get_string_by_id( $string_id );
					// set post_type
					$final_strings[ $string_id ][ 'post_type' ] = 'string';
					// set from_lang
					$final_strings[ $string_id ][ 'from_lang' ] = $source_language;
					$final_strings[ $string_id ][ 'from_lang_string' ] = ucfirst( $sitepress->get_display_language_name( $source_language,
					                                                                                                     $sitepress->get_admin_language() ) );
					// set to_langs
					$final_strings[ $string_id ][ 'to_langs' ] = $data[ 'to_langs' ];
					// set comma separated to_langs -> to_langs_string
					// set comma separated to_langs -> to_langs_string
					$language_names = array();
					foreach ( $final_strings[ $string_id ][ 'to_langs' ] as $language_code => $value ) {
						$language_names[ ] = ucfirst( $sitepress->get_display_language_name( $language_code,
						                                                                     $sitepress->get_admin_language() ) );
					}
					$final_strings[ $string_id ][ 'to_langs_string' ] = implode( ", ", $language_names );
				}
			}
		}

		return $final_strings;
	}

    function get_translation_job(
        $job_id,
        $include_non_translatable_elements = false,
        $auto_assign = false,
        $revisions = 0
    ) {

        return apply_filters (
            'wpml_get_translation_job',
            $job_id,
            $include_non_translatable_elements,
            $revisions
        );
    }

	function get_translation_job_id($trid, $language_code){
        global $wpdb;

		$job_id = $wpdb->get_var($wpdb->prepare("
			SELECT tj.job_id FROM {$wpdb->prefix}icl_translate_job tj
				JOIN {$wpdb->prefix}icl_translation_status ts ON tj.rid = ts.rid
				JOIN {$wpdb->prefix}icl_translations t ON ts.translation_id = t.translation_id
				WHERE t.trid = %d AND t.language_code=%s
				ORDER BY tj.job_id DESC LIMIT 1
		", $trid, $language_code));

		return $job_id;
	}

	function _save_translation_field($tid, $field){
		global $wpdb, $wpml_post_translations;

		$update = array();
		if ( isset( $field[ 'data' ] ) ) {
		$update['field_data_translated'] = $this->encode_field_data($field['data'], $field['format']);
		}
		if(isset($field['finished']) && $field['finished']){
			$update['field_finished'] = 1;
		}
		$wpdb->update($wpdb->prefix . 'icl_translate', $update, array('tid'=>$tid));
	}

	function save_translation($data){
		global $wpdb, $sitepress, $ICL_Pro_Translation;

		$new_post_id = false;
		$is_incomplete = false;

		foreach($data['fields'] as $field){
			$this->_save_translation_field($field['tid'], $field);
			if(!isset($field['finished']) || !$field['finished']){
				$is_incomplete = true;
			}
		}

		//check if translation job still exists
		$job_count = $wpdb->get_var( $wpdb->prepare( "SELECT count(1) FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $data[ 'job_id' ] ) );
		if ( $job_count == 0 ) {
			if ( defined( 'XMLRPC_REQUEST' ) || defined( 'DOING_AJAX' ) ) {
				return;
			} else{
			wp_redirect( admin_url( sprintf( 'admin.php?page=%s', WPML_TM_FOLDER . '/menu/translations-queue.php', 'job-cancelled' ) ) );
			exit;
		}
		}

		if(!empty($data['complete']) && !$is_incomplete){
			$wpdb->update($wpdb->prefix . 'icl_translate_job', array('translated'=>1), array('job_id'=>$data['job_id']));
			$rid = $wpdb->get_var($wpdb->prepare("SELECT rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $data['job_id']));
			$translation_id = $wpdb->get_var($wpdb->prepare("SELECT translation_id FROM {$wpdb->prefix}icl_translation_status WHERE rid=%d", $rid));

			$wpdb->update($wpdb->prefix . 'icl_translation_status', array('status'=>ICL_TM_COMPLETE, 'needs_update'=>0), array('rid'=>$rid));
			list($element_id, $trid) = $wpdb->get_row($wpdb->prepare("SELECT element_id, trid FROM {$wpdb->prefix}icl_translations WHERE translation_id=%d", $translation_id), ARRAY_N);
			$job = $this->get_translation_job($data['job_id'], true);

			$element_type_prefix = $this->get_element_type_prefix_from_job( $job );
		    $original_post = $this->get_post($job->original_doc_id, $element_type_prefix );

			$parts = explode('_', $job->original_doc_id);
			if ($this->is_external_type($element_type_prefix)) {

				// Translations are saved in the string table for 'external' types

				$id = array_pop($parts);
				unset($parts[0]);
				$element_type_prefix = apply_filters('wpml_get_package_type_prefix', $element_type_prefix, $job->original_doc_id);

				foreach($job->elements as $field){
					if ($field->field_translate) {
						if (function_exists('icl_st_is_registered_string')) {
							$string_id = icl_st_is_registered_string($element_type_prefix, $id . '_' . $field->field_type);
							if (!$string_id) {
								icl_register_string($element_type_prefix, $id . '_' . $field->field_type, self::decode_field_data($field->field_data, $field->field_format));
								$string_id = icl_st_is_registered_string($element_type_prefix, $id . '_' . $field->field_type);
							}
							if ($string_id) {
								icl_add_string_translation($string_id, $job->language_code, self::decode_field_data($field->field_data_translated, $field->field_format), ICL_TM_COMPLETE);
							}
						}
					}
				}
			} else {

				if(!is_null($element_id)){
					$postarr['ID'] = $_POST['post_ID'] = $element_id;
				}

				foreach($job->elements as $field){
					switch($field->field_type){
						case 'title':
							$postarr['post_title'] = self::decode_field_data($field->field_data_translated, $field->field_format);
							break;
						case 'body':
							$postarr['post_content'] = self::decode_field_data($field->field_data_translated, $field->field_format);
							break;
						case 'excerpt':
							$postarr['post_excerpt'] = self::decode_field_data($field->field_data_translated, $field->field_format);
							break;
						case 'URL':
							$postarr['post_name'] = self::decode_field_data($field->field_data_translated, $field->field_format);
							break;
						default:
							break;
					}
				}

				$postarr['post_author'] = $original_post->post_author;
				$postarr['post_type'] = $original_post->post_type;

				if ( $sitepress->get_setting( 'sync_comment_status' ) ) {
					$postarr[ 'comment_status' ] = $original_post->comment_status;
				}
				if ( $sitepress->get_setting( 'sync_ping_status' ) ) {
					$postarr[ 'ping_status' ] = $original_post->ping_status;
				}
				if ( $sitepress->get_setting( 'sync_page_ordering' ) ) {
					$postarr[ 'menu_order' ] = $original_post->menu_order;
				}
				if ( $sitepress->get_setting( 'sync_private_flag' ) && $original_post->post_status == 'private' ) {
					$postarr[ 'post_status' ] = 'private';
				}
				if ( $sitepress->get_setting( 'sync_post_date' ) ) {
					$postarr[ 'post_date' ] = $original_post->post_date;
				}

				//set as draft or the same status as original post
				$postarr[ 'post_status' ] = ! $sitepress->get_setting( 'translated_document_status' ) ? 'draft' : $original_post->post_status;

				if ( $original_post->post_parent ) {
					$post_parent_trid = $wpdb->get_var( $wpdb->prepare( "	SELECT trid
																			FROM {$wpdb->prefix}icl_translations
																			WHERE element_type LIKE 'post%%'
																			AND element_id=%d",
					                                                    $original_post->post_parent ) );
					if ( $post_parent_trid ) {
						$parent_id = $wpdb->get_var( $wpdb->prepare( "	SELECT element_id
																		FROM {$wpdb->prefix}icl_translations
																		WHERE element_type LIKE 'post%%' AND trid=%d AND language_code=%s",
						                                             $post_parent_trid,
						                                             $job->language_code ) );
					}
				}

				if ( isset( $parent_id ) && $sitepress->get_setting( 'sync_page_parent' ) ) {
					$_POST['post_parent'] = $postarr['post_parent'] = $parent_id;
					$_POST['parent_id'] = $postarr['parent_id'] = $parent_id;
				}

				$_POST['trid'] = $trid;
				$_POST['lang'] = $job->language_code;
				$_POST['skip_sitepress_actions'] = true;

				$postarr = apply_filters('icl_pre_save_pro_translation', $postarr);

				if(isset($element_id)){ // it's an update so dont change the url
					$postarr['post_name'] = $wpdb->get_var($wpdb->prepare("SELECT post_name FROM {$wpdb->posts} WHERE ID=%d", $element_id));
				}

				if(isset($element_id)){ // it's an update so dont change post date
					$existing_post = get_post($element_id);
					$postarr['post_date'] = $existing_post->post_date;
					$postarr['post_date_gmt'] = $existing_post->post_date_gmt;
				}

				$new_post_id = $this->icl_insert_post( $postarr, $job->language_code );
				icl_cache_clear( $postarr['post_type'] . 's_per_language' ); // clear post counter per language in cache

			    // set taxonomies for users with limited caps
			    if ( !current_user_can( 'manage-categories' ) && !empty( $postarr[ 'tax_input' ] ) ) {
				    foreach ( $postarr[ 'tax_input' ] as $taxonomy => $terms ) {
					    wp_set_post_terms( $new_post_id, $terms, $taxonomy, false ); // true to append to existing tags | false to replace existing tags
				    }
			    }

				do_action('icl_pro_translation_saved', $new_post_id, $data['fields']);

				if (!isset($postarr['post_name']) || empty($postarr['post_name'])) {
					// Allow identical slugs
					$post_name = sanitize_title($postarr['post_title']);

					// for Translated documents options:Page URL = Translate
									if(isset($data['fields']['URL']['data']) && $data['fields']['URL']['data']){
											$post_name = $data['fields']['URL']['data'];
									}

					$post_name_rewritten = $wpdb->get_var($wpdb->prepare("SELECT post_name FROM {$wpdb->posts} WHERE ID=%d", $new_post_id));

					$post_name_base = $post_name;

					if ( $post_name != $post_name_rewritten || $postarr[ 'post_type' ] == 'post' || $postarr[ 'post_type' ] == 'page' ) {
						$incr = 1;
						do{

							$exists = $wpdb->get_var($wpdb->prepare("
								SELECT p.ID FROM {$wpdb->posts} p
									JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID
								WHERE p.ID <> %d AND t.language_code = %s AND p.post_name=%s
							",  $new_post_id, $job->language_code, $post_name));

							if($exists){
								$incr++;
							}else{
								break;
							}
							$post_name = $post_name_base . '-' . $incr;
						}while($exists);

						$wpdb->update($wpdb->posts, array('post_name' => $post_name), array('ID' => $new_post_id));
					}
				}

				if ( $ICL_Pro_Translation ) {
					/** @var WPML_Pro_Translation $ICL_Pro_Translation */
					$ICL_Pro_Translation->_content_fix_links_to_translated_content( $new_post_id, $job->language_code );
				}

				// update body translation with the links fixed
				$new_post_content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID=%d", $new_post_id));
				foreach($job->elements as $jel){
					if($jel->field_type=='body'){
						$fields_data_translated = $this->encode_field_data($new_post_content, $jel->field_format);
						break;
					}
				}
			    if ( isset( $fields_data_translated ) ) {
				$wpdb->update($wpdb->prefix.'icl_translate', array('field_data_translated'=>$fields_data_translated), array('job_id'=>$data['job_id'], 'field_type'=>'body'));
			    }

				// set stickiness
				//is the original post a sticky post?
				$sticky_posts = get_option('sticky_posts');
				$is_original_sticky = $original_post->post_type=='post' && in_array($original_post->ID, $sticky_posts);

				if($is_original_sticky && $sitepress->get_setting('sync_sticky_flag')){
					stick_post($new_post_id);
				}else{
					if($original_post->post_type=='post' && !is_null($element_id)){
						unstick_post($new_post_id); //just in case - if this is an update and the original post stckiness has changed since the post was sent to translation
					}
				}

				//sync plugins texts
				foreach((array)$this->settings['custom_fields_translation'] as $cf => $op){
					if ($op == 1) {
						update_post_meta($new_post_id, $cf, get_post_meta($original_post->ID,$cf,true));
					}
				}

				// set specific custom fields
				$copied_custom_fields = array('_top_nav_excluded', '_cms_nav_minihome');
				foreach($copied_custom_fields as $ccf){
					$val = get_post_meta($original_post->ID, $ccf, true);
					update_post_meta($new_post_id, $ccf, $val);
				}

				// sync _wp_page_template
				if($sitepress->get_setting('sync_page_template')){
					$_wp_page_template = get_post_meta($original_post->ID, '_wp_page_template', true);
					if(!empty($_wp_page_template)){
						update_post_meta($new_post_id, '_wp_page_template', $_wp_page_template);
					}
				}

								// sync post format
								if ( $sitepress->get_setting('sync_post_format' ) ) {
									$_wp_post_format = get_post_format( $original_post->ID );
									set_post_format( $new_post_id, $_wp_post_format );
								}

				// set the translated custom fields if we have any.
				foreach((array)$this->settings['custom_fields_translation'] as $field_name => $val){
					if ($val == 2) { // should be translated
						// find it in the translation
							foreach ( $job->elements as $eldata ) {
							if ($eldata->field_data == $field_name) {
								if (preg_match("/field-(.*?)-name/", $eldata->field_type, $match)) {
									$field_id = $match[1];
										$field_translation = false;
										foreach ( $job->elements as $v ) {
										if($v->field_type=='field-'.$field_id){
											$field_translation = self::decode_field_data($v->field_data_translated, $v->field_format) ;
										}
										if($v->field_type=='field-'.$field_id.'-type'){
											$field_type = $v->field_data;
										}
									}
										if ( $field_translation !== false && isset( $field_type ) && $field_type == 'custom_field' ) {
										$field_translation = str_replace ( '&#0A;', "\n", $field_translation );
										// always decode html entities  eg decode &amp; to &
										$field_translation = html_entity_decode($field_translation);
										update_post_meta($new_post_id, $field_name, $field_translation);
									}
								}
							}
						}
					}
				}
				$link = get_edit_post_link($new_post_id);
				if ($link == '') {
					// the current user can't edit so just include permalink
					$link = get_permalink($new_post_id);
				}
				if(is_null($element_id)){
					$wpdb->delete ( $wpdb->prefix . 'icl_translations', array( 'element_id' => $new_post_id, 'element_type' => 'post_' . $postarr[ 'post_type' ] ) );
					$wpdb->update ( $wpdb->prefix . 'icl_translations', array( 'element_id' => $new_post_id), array('translation_id' => $translation_id) );
					$user_message = __('Translation added: ', 'sitepress') . '<a href="'.$link.'">' . $postarr['post_title'] . '</a>.';
				}else{
					$user_message = __('Translation updated: ', 'sitepress') . '<a href="'.$link.'">' . $postarr['post_title'] . '</a>.';
				}
				}

			if ( isset( $user_message ) ) {
				$this->add_message( array(
					                    'type' => 'updated',
					                    'text' => $user_message
				                    ) );
			}

			if ( $this->settings[ 'notification' ][ 'completed' ] != ICL_TM_NOTIFICATION_NONE ) {
				require_once ICL_PLUGIN_PATH . '/inc/translation-management/tm-notification.class.php';
				if ( $data[ 'job_id' ] ) {
					$tn_notification = new TM_Notification();
					$tn_notification->work_complete( $data[ 'job_id' ], ! is_null( $element_id ) );
				}
			}

			self::set_page_url($new_post_id);

		    if ( isset( $job ) && isset( $job->language_code ) && isset( $job->source_language_code ) ) {

			    // If the post already existed we do not remove the default category by not appending the newly added terms.
			    $append = false;
			    if ( isset( $element_id ) ) {
				    $append = true;
		}

			    WPML_Translation_Job_Terms::save_terms_from_job( $data[ 'job_id' ],
			                                               $job->language_code,
			                                               $new_post_id,
			                                               $append );
		    }
			// Set the posts mime type correctly.

			if ( isset( $original_post ) && isset( $original_post->ID ) && $original_post->post_type == 'attachment' ) {
				$attached_file = get_post_meta( $original_post->ID, '_wp_attached_file', false );
				update_post_meta( $new_post_id, '_wp_attached_file', array_pop( $attached_file ) );
				$mime_type = get_post_mime_type( $original_post->ID );
				if ( $mime_type ) {
					$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime_type ), array( 'ID' => $new_post_id ) );
				}
			}

			do_action( 'icl_pro_translation_completed', $new_post_id );

			if ( defined( 'XMLRPC_REQUEST' ) || defined( 'DOING_AJAX' ) || isset( $_POST[ 'xliff_upload' ] ) ) {
				return;
			} else {
				$action_type = is_null( $element_id ) ? 'added' : 'updated';
				$element_id = is_null( $element_id ) ? $new_post_id : $element_id;
				wp_redirect( admin_url( sprintf( 'admin.php?page=%s&%s=%d&element_type=%s', WPML_TM_FOLDER . '/menu/translations-queue.php', $action_type, $element_id, $element_type_prefix ) ) );
				exit;
			}
		} else {
			$this->messages[ ] = array( 'type' => 'updated', 'text' => __( 'Translation (incomplete) saved.', 'sitepress' ) );
		}
	}

	// returns a front end link to a post according to the user access
	// hide_empty - if current user doesn't have access to the link don't show at all
	public static function tm_post_link($post_id, $anchor = false, $hide_empty = false, $edit_link = false, $allow_draft = false, $allow_private = false){
		global $current_user;
		get_currentuserinfo();

		if(false === $anchor){
			$anchor = get_the_title($post_id);
		}
		
		$anchor = esc_html($anchor);

		$opost = get_post($post_id);
		if ( ! $opost
		     || ( ( $opost->post_status === 'draft' && ! $allow_draft )
		          || ( $opost->post_status === 'private' && ! $allow_private )
		          || $opost->post_status === 'trash' )
		        && $opost->post_author != $current_user->data->ID
		) {
			if($hide_empty || $edit_link){
				$elink = '';
			}else{
				$elink = sprintf('<i>%s</i>', $anchor);
			}
		}elseif($edit_link){
			$elink = sprintf('<a href="%s">%s</a>', get_edit_post_link($post_id), $anchor);
		}else{
			$elink = sprintf('<a href="%s">%s</a>', get_permalink($post_id), $anchor);
		}

		return $elink;

	}

	public function tm_post_permalink($post_id){
		global $current_user;
		get_currentuserinfo();

		$parts = explode('_', $post_id);
		if ($parts[0] == 'external') {

			return '';
		}

		$opost = get_post($post_id);
		if(!$opost || ($opost->post_status == 'draft' || $opost->post_status == 'private' || $opost->post_status == 'trash') && $opost->post_author != $current_user->data->ID){
			$elink = '';
		}else{
			$elink = get_permalink($post_id);
		}

		return $elink;

	}

	// when the translated post was created, we have the job_id and need to update the job
	function save_job_fields_from_post($job_id, $post){
		global $wpdb;
		$data['complete'] = 1;
		$data['job_id'] = $job_id;
		$job = $this->get_translation_job($job_id,1);
		if ( class_exists( 'WPML_Translation_Job_Terms' ) ) {
			$term_names = WPML_Translation_Job_Terms::get_term_field_array_for_post( $post->ID );
		} else {
			$term_names = array();
		}
		if(is_object($job) && is_array($job->elements)) {
		foreach($job->elements as $element){
			$field_data = '';
			switch($element->field_type){
				case 'title':
					$field_data = $this->encode_field_data($post->post_title, $element->field_format);
					break;
				case 'body':
					$field_data = $this->encode_field_data($post->post_content, $element->field_format);
					break;
				case 'excerpt':
					$field_data = $this->encode_field_data($post->post_excerpt, $element->field_format);
					break;
				default:
					if(false !== strpos($element->field_type, 'field-') && !empty($this->settings['custom_fields_translation'])){
						$cf_name = preg_replace('#^field-#', '', $element->field_type);
						if(isset($this->settings['custom_fields_translation'][$cf_name])){
							if($this->settings['custom_fields_translation'][$cf_name] == 1){ //copy
								//TODO: [WPML 3.3] Check when this code is run, it seems obsolete
								$field_data = get_post_meta($job->original_doc_id, $cf_name, 1);
								if(is_scalar($field_data))
								$field_data = $this->encode_field_data($field_data, $element->field_format);
								else $field_data = '';
							}elseif($this->settings['custom_fields_translation'][$cf_name] == 2){ // translate
								$field_data = get_post_meta($post->ID, $cf_name, 1);
								if(is_scalar($field_data))
								$field_data = $this->encode_field_data($field_data, $element->field_format);
								else $field_data = '';
							}
						}
					}else{
						if ( isset( $term_names[ $element->field_type ] ) ) {
							$field_data = $this->encode_field_data( $term_names[ $element->field_type ],
							                                        $element->field_format );
								}
							}
								}
		        $wpdb->update( $wpdb->prefix . 'icl_translate', array( 'field_data_translated' => $field_data, 'field_finished' => 1 ), array( 'tid' => $element->tid ) );

						}
      $this->mark_job_done($job_id);
					}
			}

	public static function determine_translated_taxonomies($elements, $taxonomy, $translated_language){
        global $sitepress;
		$translated_elements = false;
		foreach($elements as $k=>$element){
			$term = get_term_by('name', $element, $taxonomy);
			if ($term) {
				$trid = $sitepress->get_element_trid($term->term_taxonomy_id, 'tax_' . $taxonomy);
				$translations = $sitepress->get_element_translations($trid, 'tax_' . $taxonomy);
				if(isset($translations[$translated_language])){
					$translated_elements[$k] = $translations[$translated_language]->name;
				}else{
					$translated_elements[$k] = '';
				}
			} else {
				$translated_elements[$k] = '';
			}
		}

		return $translated_elements;
	}

	function mark_job_done($job_id){
		global $wpdb;
		$wpdb->update($wpdb->prefix.'icl_translate_job', array('translated'=>1), array('job_id'=>$job_id));
		$wpdb->update($wpdb->prefix.'icl_translate', array('field_finished'=>1), array('job_id'=>$job_id));
	}

	function resign_translator($job_id){
		global $wpdb;
		list($translator_id, $rid) = $wpdb->get_row($wpdb->prepare("SELECT translator_id, rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id), ARRAY_N);

		if(!empty($translator_id)){
			if($this->settings['notification']['resigned'] != ICL_TM_NOTIFICATION_NONE){
				require_once ICL_PLUGIN_PATH . '/inc/translation-management/tm-notification.class.php';
				if($job_id){
					$tn_notification = new TM_Notification();
					$tn_notification->translator_resigned($translator_id, $job_id);
				}
			}
		}

		$wpdb->update($wpdb->prefix.'icl_translate_job', array('translator_id'=>0), array('job_id'=>$job_id));
		$wpdb->update($wpdb->prefix.'icl_translation_status', array('translator_id'=>0, 'status'=>ICL_TM_WAITING_FOR_TRANSLATOR), array('rid'=>$rid));
	}

	function remove_translation_job($job_id, $new_translation_status = ICL_TM_WAITING_FOR_TRANSLATOR, $new_translator_id = 0){
		global $wpdb;

		$error = false;

		list($prev_translator_id, $rid) = $wpdb->get_row($wpdb->prepare("SELECT translator_id, rid FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id), ARRAY_N);

		$wpdb->update($wpdb->prefix . 'icl_translate_job', array('translator_id' => $new_translator_id), array('job_id' => $job_id));
		$wpdb->update($wpdb->prefix . 'icl_translate', array('field_data_translated' => '', 'field_finished' => 0), array('job_id' => $job_id));

		if($rid){
			$data = array( 'status' => $new_translation_status, 'translator_id' => $new_translator_id );
			$data_where = array( 'rid' => $rid );
			$wpdb->update( $wpdb->prefix . 'icl_translation_status', $data, $data_where );

			if($this->settings['notification']['resigned'] == ICL_TM_NOTIFICATION_IMMEDIATELY && !empty($prev_translator_id)){
				$tn_notification = new TM_Notification();
				$tn_notification->translator_removed($prev_translator_id, $job_id);
				TM_Notification::mail_queue();
			}
		}else{
			$error = sprintf(__('Translation entry not found for: %d', 'wpml-translation-management'), $job_id);
		}

		return $error;
	}

	function abort_translation(){
		$job_id = $_POST['job_id'];
		$message = '';

		$error = $this->remove_translation_job($job_id, ICL_TM_WAITING_FOR_TRANSLATOR, 0);
		if(!$error){
			$message = __('Job removed', 'wpml-translation-management');
		}

		echo wp_json_encode(array('message' => $message, 'error' => $error));
		exit;

	}

	// $translation_id - int or array
	function cancel_translation_request($translation_id){
		global $wpdb, $WPML_String_Translation;;

		if ( is_array( $translation_id ) ) {
			foreach ( $translation_id as $id ) {
				$this->cancel_translation_request( $id );
			}
		} else {

			if ( $WPML_String_Translation && wpml_mb_strpos( $translation_id, 'string|' ) === 0 ) {
				//string translations get handled in wpml-string-translation
				//first remove the "string|" prefix
				$id = substr( $translation_id, 7 );
				//then send it to the respective function in wpml-string-translation
				$WPML_String_Translation->cancel_local_translation( $id );

				return;
			}

            list( $rid, $translator_id ) = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT rid, translator_id
                     FROM {$wpdb->prefix}icl_translation_status
                     WHERE translation_id=%d
                       AND ( status = %d OR status = %d )",
                    $translation_id,
                    ICL_TM_WAITING_FOR_TRANSLATOR,
                    ICL_TM_IN_PROGRESS
                ), ARRAY_N);
            if ( !$rid ) {
                return;
            }
			$job_id = $wpdb->get_var($wpdb->prepare("SELECT job_id FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d AND revision IS NULL ", $rid));

			if($this->settings['notification']['resigned'] == ICL_TM_NOTIFICATION_IMMEDIATELY && !empty($translator_id)){
				$tn_notification = new TM_Notification();
				$tn_notification->translator_removed($translator_id, $job_id);
				TM_Notification::mail_queue();
			}

			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translate_job WHERE job_id=%d", $job_id));
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translate WHERE job_id=%d", $job_id));

			$max_job_id = $wpdb->get_var($wpdb->prepare("SELECT MAX(job_id) FROM {$wpdb->prefix}icl_translate_job WHERE rid=%d", $rid));
			if($max_job_id){
				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}icl_translate_job SET revision = NULL WHERE job_id=%d", $max_job_id));
				$previous_state = $wpdb->get_var( $wpdb->prepare( "SELECT _prevstate FROM {$wpdb->prefix}icl_translation_status WHERE translation_id = %d", $translation_id ) );
				if ( !empty( $previous_state ) ) {
					$previous_state = unserialize( $previous_state );
					$arr_data = array(
							'status'              => $previous_state[ 'status' ],
							'translator_id'       => $previous_state[ 'translator_id' ],
							'needs_update'        => $previous_state[ 'needs_update' ],
							'md5'                 => $previous_state[ 'md5' ],
							'translation_service' => $previous_state[ 'translation_service' ],
							'translation_package' => $previous_state[ 'translation_package' ],
							'timestamp'           => $previous_state[ 'timestamp' ],
							'links_fixed'         => $previous_state[ 'links_fixed' ]
					);
					$data_where = array( 'translation_id' => $translation_id );
					$wpdb->update( $wpdb->prefix . 'icl_translation_status', $arr_data, $data_where );
				}
			}else{
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translation_status WHERE translation_id=%d", $translation_id));
			}

			// delete record from icl_translations if trid is null
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}icl_translations WHERE translation_id=%d AND element_id IS NULL", $translation_id));

		}

	}


	function _array_keys_recursive( $arr )
	{
		$arr_rec_ret = array();
		foreach ( (array)$arr as $k => $v ) {
			if ( is_array( $v ) ) {
				$arr_rec_ret[ $k ] = $this->_array_keys_recursive( $v );
			} else {
				$arr_rec_ret[$k] = $v;
			}
		}

		return $arr_rec_ret;
	}


    function read_settings_recursive($config_settings){
	    global $sitepress;
		$settings_portion = false;
		foreach($config_settings as $s){
			if(isset($s['key'])){
				if(!is_numeric(key($s['key']))){
					$sub_key[0] = $s['key'];
				}else{
					$sub_key = $s['key'];
				}
				$read_settings_recursive = $this->read_settings_recursive( $sub_key );
				if($read_settings_recursive) {
					$sitepress->set_setting( $s[ 'attr' ][ 'name' ], $read_settings_recursive );
				}
			}else{
	            $sitepress->set_setting($s[ 'attr' ][ 'name' ], $s['value']);
                $settings_portion[$s['attr']['name']] = $s['value'];
			}
		}

        return $settings_portion;
	}

	function render_option_writes($name, $value, $key=''){
		if(!defined('WPML_ST_FOLDER')) return;
		//Cache the previous option, when called recursively
		static $option = false;

		if(!$key){
			$option = maybe_unserialize(get_option($name));
			if(is_object($option)){
				$option = (array)$option;
			}
		}

		$admin_option_names = get_option('_icl_admin_option_names');

		// determine theme/plugin name (string context)
		$es_context = '';

	    $context = '';
	    $slug = '';
		foreach($admin_option_names as $context => $element) {
			$found = false;
			foreach ( (array)$element as $slug => $options ) {
				$found = false;
				foreach ( (array)$options as $option_key => $option_value ) {
					$found = false;
					$es_context = '';
					if( $option_key == $name ) {
						if ( is_scalar( $option_value ) ) {
							$es_context = 'admin_texts_' . $context . '_' . $slug;
							$found = true;
						} elseif ( is_array( $option_value ) && is_array( $value ) && ( $option_value == $value ) ) {
							$es_context = 'admin_texts_' . $context . '_' . $slug;
							$found = true;
						}
					}
					if($found) break;
				}
				if($found) break;
			}
			if($found) break;
		}

		echo '<ul class="icl_tm_admin_options">';
		echo '<li>';

		$context_html = '';
		if(!$key){
			$context_html = '[' . $context . ': ' . $slug . '] ';
		}

		if(is_scalar($value)){
			preg_match_all('#\[([^\]]+)\]#', $key, $matches);

			if(count($matches[1]) > 1){
				$o_value = $option;
				for($i = 1; $i < count($matches[1]); $i++){
					$o_value = $o_value[$matches[1][$i]];
				}
				$o_value = $o_value[$name];
				$edit_link = '';
			}else{
				if(is_scalar($option)){
					$o_value = $option;
				}elseif(isset($option[$name])){
					$o_value = $option[$name];
				}else{
					$o_value = '';
				}

				if(!$key){
					if(icl_st_is_registered_string($es_context, $name)) {
						$edit_link = '[<a href="'.admin_url('admin.php?page='.WPML_ST_FOLDER.'/menu/string-translation.php&context='.$es_context) . '">' . __('translate', 'sitepress') . '</a>]';
					} else {
						$edit_link = '<div class="updated below-h2">' . __('string not registered', 'sitepress') . '</div>';
					}
				}else{
					$edit_link = '';
				}
			}

			if(false !== strpos($name, '*')){
				$o_value = '<span style="color:#bbb">{{ '  . __('Multiple options', 'wpml-translation-management') .  ' }}</span>';
			}else{
				$o_value = esc_html($o_value);
				if(strlen($o_value) > 200){
					$o_value = substr($o_value, 0, 200) . ' ...';
				}
			}
			echo '<li>' . $context_html . $name . ': <i>' . $o_value  . '</i> ' . $edit_link . '</li>';
		}else{
			$edit_link = '[<a href="'.admin_url('admin.php?page='.WPML_ST_FOLDER.'/menu/string-translation.php&context='.$es_context) . '">' . __('translate', 'sitepress') . '</a>]';
			echo '<strong>' . $context_html  . $name . '</strong> ' . $edit_link;
			if(!icl_st_is_registered_string($es_context, $name)) {
				$notice = '<div class="updated below-h2">' . __('some strings might be not registered', 'sitepress') . '</div>';
				echo $notice;
			}

			foreach((array)$value as $o_key=>$o_value){
				$this->render_option_writes($o_key, $o_value, $o_key . '[' . $name . ']');
			}

			//Reset cached data
			$option = false;
		}
		echo '</li>';
		echo '</ul>';
	}

	function _override_get_translatable_documents($types){
		global $wp_post_types;
		foreach($types as $k=>$type){
			if(isset($this->settings['custom_types_readonly_config'][$k]) && !$this->settings['custom_types_readonly_config'][$k]){
				unset($types[$k]);
			}
		}
		foreach($this->settings['custom_types_readonly_config'] as $cp=>$translate){
			if($translate && !isset($types[$cp]) && isset($wp_post_types[$cp])){
				$types[$cp] = $wp_post_types[$cp];
			}
		}
		return $types;
	}

	function _override_get_translatable_taxonomies($taxs_obj_type){
		global $wp_taxonomies, $sitepress;

		$taxs = $taxs_obj_type['taxs'];

		$object_type = $taxs_obj_type['object_type'];
		foreach($taxs as $k=>$tax){
			if(!$sitepress->is_translated_taxonomy($tax)){
				unset($taxs[$k]);
			}
		}

		foreach($this->settings['taxonomies_readonly_config'] as $tx=>$translate){
			if($translate && !in_array($tx, $taxs) && isset($wp_taxonomies[$tx]) && in_array($object_type, $wp_taxonomies[$tx]->object_type)){
				$taxs[] = $tx;
			}
		}

		$ret = array('taxs'=>$taxs, 'object_type'=>$taxs_obj_type['object_type']);

		return $ret;
	}


	/**
	 * @param array $info
	 *
	 * @deprecated @since 3.2 Use TranslationProxy::get_current_service_info instead
	 *
	 * @return array
	 */
	public static function current_service_info( $info = array() ) {
		return TranslationProxy::get_current_service_info($info);
	}

	public function clear_cache() {
		global $wpdb;
		delete_option($wpdb->prefix . 'icl_translators_cached');
		delete_option($wpdb->prefix . 'icl_non_translators_cached');
	}

	// shows post content for visual mode (iframe) in translation editor
	function _show_post_content(){

		$post = get_post($_GET['post_id']);

		if($post){

			if(0 === strpos($_GET['field_type'], 'field-')){
				// A Types field
				$data = get_post_meta($_GET['post_id'], preg_replace('#^field-#', '', $_GET['field_type']), true);
			}else{
				if (isset($post->string_data[$_GET['field_type']])) {
					// A string from an external
					$data = $post->string_data[$_GET['field_type']];
				} else {
					// The post body.
					remove_filter('the_content', 'do_shortcode', 11);
					$data = apply_filters('the_content', $post->post_content);
				}
			}

			if(@intval($_GET['rtl'])){
				$rtl = ' dir="rtl"';
			}else{
				$rtl = '';
			}
			echo '<html'.$rtl.'>';
			echo '<body>';
			echo $data;
			echo '</body>';
			echo '</html>';
			exit;
		}else{
			wp_die(__('Post not found!', 'sitepress'));
		}
		exit;
	}

	function _user_search(){
		$q = $_POST['q'];

		$non_translators = self::get_blog_not_translators();

		$matched_users = array();
		foreach($non_translators as $t){
			if(false !== stripos($t->user_login, $q) || false !== stripos($t->display_name, $q)){
				$matched_users[] = $t;
			}
			if(count($matched_users) == 100) break;
		}

		if(!empty($matched_users)){
			$cssheight  = count($matched_users) > 10 ? '200' : 20*count($matched_users) + 5;
			echo '<select size="10" class="icl_tm_auto_suggest_dd" style="height:'.$cssheight.'px">';
			foreach($matched_users as $u){
				echo '<option value="' . $u->ID . '|' . esc_attr($u->display_name).'">'.$u->display_name . ' ('.$u->user_login.')'.'</option>';

			}
			echo '</select>';

		}else{
			echo '&nbsp;<span id="icl_user_src_nf">';
			_e('No matches', 'sitepress');
			echo '</span>';
		}


		exit;

	}

	// set slug according to user preference
	static function set_page_url($post_id){

		global $sitepress, $sitepress_settings, $wpdb;

		if($sitepress_settings['translated_document_page_url'] == 'copy-encoded'){

			$post = $wpdb->get_row($wpdb->prepare("SELECT post_type FROM {$wpdb->posts} WHERE ID=%d", $post_id));
			$translation_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}icl_translations WHERE element_id=%d AND element_type=%s", $post_id, 'post_' . $post->post_type));

			$encode_url = $wpdb->get_var($wpdb->prepare("SELECT encode_url FROM {$wpdb->prefix}icl_languages WHERE code=%s", $translation_row->language_code));
			if($encode_url){

				$trid = $sitepress->get_element_trid($post_id, 'post_' . $post->post_type);
				$original_post_id = $wpdb->get_var($wpdb->prepare("SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid=%d AND source_language_code IS NULL", $trid));
				$post_name_original = $wpdb->get_var($wpdb->prepare("SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $original_post_id));

				$post_name_to_be = $post_name_original;
				$incr = 1;
				do{
					$taken = $wpdb->get_var($wpdb->prepare("
						SELECT ID FROM {$wpdb->posts} p
						JOIN {$wpdb->prefix}icl_translations t ON p.ID = t.element_id
						WHERE ID <> %d AND t.element_type = %s AND t.language_code = %s AND p.post_name = %s
						", $post_id, 'post_' . $post->post_type, $translation_row->language_code, $post_name_to_be ));
					if($taken){
						$incr++;
						$post_name_to_be = $post_name_original . '-' . $incr;
					}else{
						$taken = false;
					}
				}while($taken == true);
				$wpdb->update($wpdb->posts, array('post_name' => $post_name_to_be), array('ID' => $post_id));
			}
		}

	}

	/**
	 * @param $postarr
	 *
	 * @param $lang
	 *
	 * @return int|WP_Error
	 */
	public function icl_insert_post( $postarr, $lang )
	{
		global $sitepress;
		$current_language = $sitepress->get_current_language();
		$sitepress->switch_lang( $lang, false );
		$new_post_id = wp_insert_post( $postarr );
		$sitepress->switch_lang( $current_language, false );

		return $new_post_id;
	}

	/**
	 * Add missing language to posts
	 *
	 * @param array $post_types
	 */
	protected function add_missing_language_to_posts( $post_types )
	{
		global $wpdb;

		//This will be improved when it will be possible to pass an array to the IN clause
		$posts_prepared = "SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_type IN ('" . implode("', '", esc_sql($post_types)) . "')";
		$posts = $wpdb->get_results( $posts_prepared );
		if ( $posts ) {
			foreach ( $posts as $post ) {
				$this->add_missing_language_to_post( $post );
			}
		}
	}

	/**
	 * Add missing language to a given post
	 *
	 * @param WP_Post $post
	 */
	protected function add_missing_language_to_post( $post ) {
		global $sitepress, $wpdb;

		$query_prepared   = $wpdb->prepare( "SELECT translation_id, language_code FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d", array( 'post_' . $post->post_type, $post->ID ) );
		$query_results    = $wpdb->get_row( $query_prepared );

		//if translation exists
		if (!is_null($query_results)){
			$translation_id   = $query_results->translation_id;
			$language_code    = $query_results->language_code;
		}else{
			$translation_id   = null;
			$language_code    = null;
		}

		$urls             = $sitepress->get_setting( 'urls' );
		$is_root_page     = $urls && isset( $urls[ 'root_page' ] ) && $urls[ 'root_page' ] == $post->ID;
		$default_language = $sitepress->get_default_language();

		if ( !$translation_id && !$is_root_page && !in_array( $post->post_status, array( 'auto-draft' ) ) ) {
			$sitepress->set_element_language_details( $post->ID, 'post_' . $post->post_type, null, $default_language );
		} elseif ( $translation_id && $is_root_page ) {
			$trid = $sitepress->get_element_trid( $post->ID, 'post_' . $post->post_type );
			if ( $trid ) {
				$sitepress->delete_element_translation( $trid, 'post_' . $post->post_type );
			}
		} elseif ( $translation_id && !$language_code && $default_language ) {
			$where = array( 'translation_id' => $translation_id );
			$data  = array( 'language_code' => $default_language );
			$wpdb->update( $wpdb->prefix . 'icl_translations', $data, $where );
		}
	}

	/**
	 * Add missing language to taxonomies
	 *
	 * @param array $post_types
	 */
	protected function add_missing_language_to_taxonomies( $post_types )
	{
		global $sitepress, $wpdb;
		$taxonomy_types = array();
		foreach ( $post_types as $post_type ) {
			$taxonomy_types = array_merge( $sitepress->get_translatable_taxonomies( true, $post_type ), $taxonomy_types );
		}
		$taxonomy_types = array_unique( $taxonomy_types );
		$taxonomies     = $wpdb->get_results( "SELECT taxonomy, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (" . wpml_prepare_in( $taxonomy_types ) . ")" );
		if ( $taxonomies ) {
			foreach ( $taxonomies as $taxonomy ) {
				$this->add_missing_language_to_taxonomy( $taxonomy );
			}
		}
	}

	/**
	 * Add missing language to a given taxonomy
	 *
	 * @param OBJECT $taxonomy
	 */
	protected function add_missing_language_to_taxonomy( $taxonomy )
	{
		global $sitepress, $wpdb;
		$tid_prepared = $wpdb->prepare( "SELECT translation_id FROM {$wpdb->prefix}icl_translations WHERE element_type=%s AND element_id=%d", 'tax_' . $taxonomy->taxonomy, $taxonomy->term_taxonomy_id );
		$tid          = $wpdb->get_var( $tid_prepared );
		if ( !$tid ) {
			$sitepress->set_element_language_details( $taxonomy->term_taxonomy_id, 'tax_' . $taxonomy->taxonomy, null, $sitepress->get_default_language() );
		}
	}

	/**
	 * Add missing language to comments
	 */
	protected function add_missing_language_to_comments()
	{
		global $sitepress, $wpdb;
		$comment_ids_prepared = $wpdb->prepare( "SELECT c.comment_ID FROM {$wpdb->comments} c LEFT JOIN {$wpdb->prefix}icl_translations t ON t.element_id = c.comment_id AND t.element_type=%s WHERE t.element_id IS NULL", 'comment' );
		$comment_ids          = $wpdb->get_col( $comment_ids_prepared );
		if ( $comment_ids ) {
			foreach ( $comment_ids as $comment_id ) {
				$sitepress->set_element_language_details( $comment_id, 'comment', null, $sitepress->get_default_language() );
			}
		}
	}

	/**
	 * Add missing language information to entities that don't have this
	 * information configured.
	 */
	public function add_missing_language_information()
	{
		global $sitepress;
		$translatable_documents = array_keys( $sitepress->get_translatable_documents(true) );
		if( $translatable_documents ) {
			$this->add_missing_language_to_posts( $translatable_documents );
			$this->add_missing_language_to_taxonomies( $translatable_documents );
		}
		$this->add_missing_language_to_comments();
	}

	public static function update_translation_batch( $batch_name, $tp_id = false ) {
		global $wpdb;

		if ( ! $batch_name ) {
			return false;
		}

		$cache_key   = md5( $batch_name );
		$cache_group = 'update_translation_batch';
		$cache_found = false;

		$batch_id = wp_cache_get( $cache_key, $cache_group, false, $cache_found );

		if ( $cache_found ) {
			return $batch_id;
		}

		$batch_id_sql = "SELECT id FROM {$wpdb->prefix}icl_translation_batches WHERE batch_name=%s";
		$batch_id_prepared = $wpdb->prepare($batch_id_sql, array($batch_name));
		$batch_id = $wpdb->get_var($batch_id_prepared);

		if(!$batch_id) {
			$data = array(
				'batch_name'  => $batch_name,
				'last_update' => date( 'Y-m-d H:i:s' )
			);
			if ( $tp_id ) {
				if ( $tp_id == 'local' ) {
					$tp_id = 0;
				}
				$data[ 'tp_id' ] = $tp_id;
			}
			$wpdb->insert( $wpdb->prefix . 'icl_translation_batches', $data );
			$batch_id = $wpdb->insert_id;

			wp_cache_set( $cache_key, $batch_id, $cache_group );
		}
		return $batch_id;
	}

	public static function include_underscore_templates( $name ) {
		$dir_str = WPML_TM_PATH . '/res/js/' . $name . '/templates/';
		$dir     = opendir( $dir_str );
		while ( ( $currentFile = readdir( $dir ) ) !== false ) {
			if ( $currentFile == '.' || $currentFile == '..' || $currentFile[ 0 ] == '.' ) {
				continue;
			}

			/** @noinspection PhpIncludeInspection */
			include $dir_str . $currentFile;
		}
		closedir( $dir );
	}

	public static function get_job_status_string( $status_id, $needs_update = false ) {
		$job_status_text = TranslationManagement::status2text( $status_id );
		if ( $needs_update ) {
			$job_status_text .= __( ' - (needs update)', 'wpml-translation-management' );
		}

		return $job_status_text;
	}

	function display_basket_notification($position) {
		if(class_exists('ICL_AdminNotifier') && class_exists('TranslationProxy_Basket')) {
			$positions = TranslationProxy_Basket::get_basket_notification_positions();
			if(isset($positions[$position])) {
				ICL_AdminNotifier::display_messages( 'translation-basket-notification' );
			}
		}
	}

	/**
	 * @param $data
	 */
	private function set_translation_jobs_basket( $data ) {
		if ( isset( $data[ 'batch' ] ) && $data[ 'batch' ] ) {
			$batch                        = $data[ 'batch' ];
			$translation_jobs_basket_full = TranslationProxy_Basket::get_basket();

			$this->translation_jobs_basket[ 'name' ] = $translation_jobs_basket_full[ 'name' ];
			foreach ( $batch as $batch_item ) {
				$element_type = $batch_item[ 'type' ];
				$element_id   = $batch_item[ 'post_id' ];
				if ( isset( $translation_jobs_basket_full[ $element_type ][ $element_id ] ) ) {
					$this->translation_jobs_basket[ $element_type ][ $element_id ] = $translation_jobs_basket_full[ $element_type ][ $element_id ];
				}
			}
		}
}

	/**
	 * @param $translators
	 *
	 * @return mixed
	 */
	private function set_remote_target_languages( $translators ) {
		$this->remote_target_languages = array();

		$basket_items_types = TranslationProxy_Basket::get_basket_items_types();
		foreach ( $basket_items_types as $item_type_name => $item_type ) {
			// check target languages for strings
			if ( ! empty( $this->translation_jobs_basket[ $item_type_name ] ) ) {
				foreach ( $this->translation_jobs_basket[ $item_type_name ] as $value ) {
					foreach ( $value[ 'to_langs' ] as $target_language => $target_language_selected ) {
						//for remote strings
						if ( $value[ 'from_lang' ] != $target_language && ! is_numeric( $translators[ $target_language ] ) ) {
							if ( $target_language_selected && ! in_array( $target_language,
							                                              $this->remote_target_languages )
							) {
								$this->remote_target_languages[ ] = $target_language;
							}
						}
					}
				}
			}
		}
	}

	/**
	 * @param $item_type_name
	 * @param $item_type
	 * @param $posts_basket_items
	 * @param $translators
	 * @param $basket_name
	 */
	public function send_posts_jobs( $item_type_name, $item_type, $posts_basket_items, $translators, $basket_name ) {
		// for every post in cart
		// prepare data for send_jobs() and do it
		foreach ( $posts_basket_items as $basket_item_id => $basket_item ) {
			$jobs_data                 = array();
			$jobs_data[ 'iclpost' ][ ] = $basket_item_id;
			$jobs_data[ 'tr_action' ]  = $basket_item[ 'to_langs' ];
			$jobs_data[ 'translators' ] = $translators;
			$jobs_data[ 'batch_name' ] = $basket_name;
			$this->send_jobs( $jobs_data );
		}
	}

	public function get_element_type( $trid ) {
		global $wpdb;
		$element_type_query = "SELECT element_type FROM {$wpdb->prefix}icl_translations WHERE trid=%d LIMIT 0,1";
		$element_type_prepare = $wpdb->prepare($element_type_query, $trid);
		return $wpdb->get_var($element_type_prepare);
	}

	/**
	 * @param $type
	 *
	 * @return bool
	 */
	public function is_external_type( $type ) {
		return apply_filters('wpml_is_external', false, $type);
	}

	/**
	 * @param int $post_id
	 * @param string $element_type_prefix
	 *
	 * @return mixed|null|void|WP_Post
	 */
	public function get_post( $post_id, $element_type_prefix ) {
		$item = null;
		if ( $this->is_external_type( $element_type_prefix ) ) {
			$item = apply_filters( 'wpml_get_translatable_item', null, $post_id );
		}

		if(!$item) {
			$item = get_post( $post_id );
		}

		return $item;
	}

}
