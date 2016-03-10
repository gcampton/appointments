<?php

class Appointments_Google_Calendar {

	public $api_manager = false;

	public $errors = array();

	public $admin;

	private $api_mode = 'none';

	private $access_code;

	private $description = '';

	private $summary = '';

	public $worker_id = false;

	public function __construct() {
		$appointments = appointments();

		// Try to start a session. If cannot, log it.
		if ( ! session_id() && ! @session_start() ) {
			$appointments->log( __( 'Session could not be started. This may indicate a theme issue.', 'appointments' ) );
		}

		include_once( 'gcal/class-app-gcal-admin.php' );
		$this->admin = new Appointments_Google_Calendar_Admin( $this );

		add_action( 'wp_ajax_app_gcal_export', array( $this, 'export_batch' ) );
		add_action( 'wp_ajax_app_gcal_import', array( $this, 'import' ) );

		add_action( 'appointments_gcal_sync', array( $this, 'maybe_sync' ) );

		$options = appointments_get_options();

		if ( isset( $options['gcal_api_mode'] ) ) {
			$this->api_mode = $options['gcal_api_mode'];
		}

		include_once( 'gcal/class-app-gcal-api-manager.php' );
		$this->api_manager = new Appointments_Google_Calendar_API_Manager();


		$default_creds = array();
		if ( ! empty( $options['gcal_client_id'] ) && ! empty( $options['gcal_client_secret'] ) ) {
			$default_creds['client_id'] = $options['gcal_client_id'];
			$default_creds['client_secret'] = $options['gcal_client_secret'];
			$this->api_manager->set_client_id_and_secret( $options['gcal_client_id'], $options['gcal_client_secret'] );
		}

		if ( ! empty( $options['gcal_token'] ) ) {
			$default_creds['token'] = $options['gcal_token'];
			$result = $this->api_manager->set_access_token( $options['gcal_token'] );
			if ( is_wp_error( $result ) ) {
				$this->errors[] = array( 'message' => sprintf( __( 'Error validating the access token: %s', 'appointments' ), $result->get_error_message() ) );
			}
		}

		if ( ! empty( $options['gcal_token'] ) && ! $this->is_connected() ) {
			// The token is set but appears not to be valid, let's reset everything
			$options['gcal_token'] = '';
			$options['gcal_access_code'] = '';
			$default_creds['token'] = $options['gcal_token'];
			appointments_update_options( $options );
		}

		if ( ! empty( $options['gcal_access_code'] ) ) {
			$this->access_code = $options['gcal_access_code'];
			$default_creds['access_code'] = $options['gcal_access_code'];
		}

		if ( ! empty( $options['gcal_selected_calendar'] ) ) {
			$default_creds['calendar_id'] = $options['gcal_selected_calendar'];
			$this->api_manager->set_calendar( $options['gcal_selected_calendar'] );
		}

		$this->description = ! empty( $options['gcal_description'] ) ? $options['gcal_description'] : '';
		$this->summary = ! empty( $options['gcal_summary'] ) ? $options['gcal_summary'] : '';

		$this->api_manager->set_default_credentials( $default_creds );

		add_action( 'shutdown', array( $this, 'save_new_token' ) );

		// Appointments Hooks
		$this->add_appointments_hooks();

		$this->setup_cron();
	}

	public function add_appointments_hooks() {
		if ( ! $this->is_connected() ) {
			return;
		}

		add_action( 'wpmudev_appointments_insert_appointment', array( $this, 'on_insert_appointment' ) );
		add_action( 'wpmudev_appointments_update_appointment', array( $this, 'on_update_appointment' ), 10, 3 );
		add_action( 'appointments_delete_appointment', array( $this, 'on_delete_appointment' ) );
	}

	public function remove_appointments_hooks() {
		remove_action( 'wpmudev_appointments_insert_appointment', array( $this, 'on_insert_appointment' ) );
		remove_action( 'wpmudev_appointments_update_appointment', array( $this, 'on_update_appointment' ), 10, 3 );
		remove_action( 'appointments_delete_appointment', array( $this, 'on_delete_appointment' ) );
	}

	public function setup_cron() {
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );

		if ( 'sync' === $this->get_api_mode() ) {
			$scheduled = wp_next_scheduled( 'appointments_gcal_sync' );
			if ( ! $scheduled ) {
				wp_schedule_event( current_time( 'timestamp' ), 'app-gcal', 'appointments_gcal_sync' );
			}
		}
		else {
			$scheduled = wp_next_scheduled( 'appointments_gcal_sync' );
			if ( $scheduled ) {
				wp_unschedule_event( $scheduled, 'appointments_gcal_sync' );
			}
		}
	}

	public function cron_schedules( $schedules ) {
		$schedules['app-gcal'] = array( 'interval' => HOUR_IN_SECONDS / 6,    'display' => __( 'Every 10 minutes' ) );
		return $schedules;
	}

	public function maybe_sync() {
		$appointments = appointments();

		include_once( 'gcal/class-app-gcal-importer.php' );
		$importer = new Appointments_Google_Calendar_Importer( $this );

		$start_time = current_time( 'timestamp' );
		$end_time = $start_time + ( 3600 * 24 * $appointments->get_app_limit() );
		$args = array(
			'status' => array_merge( $this->get_syncable_status(), array( 'reserved', 'removed' ) ),
			'date_query' => array(
				array(
					'field' => 'start',
					'compare' => '>=',
					'value' => date( 'Y-m-d H:i:s', $start_time )
				),
				array(
					'field' => 'end',
					'compare' => '<=',
					'value' => date( 'Y-m-d H:i:s', $end_time )
				)
			)
		);

		$processed_workers = array();
		$gcal_ids = array();
		if ( $this->workers_allowed() ) {

			// Sync workers calendars first
			$workers = appointments_get_workers();
			foreach ( $workers as $worker ) {
				$switched = $this->switch_to_worker( $worker->ID );
				if ( $switched ) {

					// Worker switched successfully
					$api_mode = $this->get_api_mode();
					if ( 'sync' === $api_mode ) {
						// Sync worker!
						$this->remove_appointments_hooks();
						$processed_workers[] = $worker->ID;

						$args['worker'] = $worker->ID;
						$apps = appointments_get_appointments( $args );
						$events = $this->get_events_list();
						unset( $args['worker'] );

						foreach ( $apps as $app ) {
							if ( $app->gcal_ID ) {
								$gcal_ids[] = $app->gcal_ID;
							}
							$this->sync_appointment( $app, $worker->ID );
						}

						/** @var Google_Service_Calendar_Event $event */
						foreach ( $events as $event ) {
							$event_id = $event->getID();
							if ( in_array( $event_id, $gcal_ids ) ) {
								// Already processed
								continue;
							}

							$importer->import_event( $event, $worker->ID );
						}

						$this->add_appointments_hooks();
					}
					$this->restore_to_default();
				}
			}
		}

		$api_mode = $this->get_api_mode();
		if ( 'sync' === $api_mode ) {
			// Sync!
			$apps = appointments_get_appointments( $args );
			$events = $this->get_events_list();
			if ( ! is_wp_error( $events ) ) {
				$this->remove_appointments_hooks();
				// Upload first all appointments to GCal
				foreach ( $apps as $app ) {
					if ( 'no_preference' === $this->get_api_scope() && appointments_get_worker( $app->worker ) ) {
						// If scope is set to No Preference and there's a worker assigned to it, do not sync this app
						continue;
					}

					if ( $app->gcal_ID ) {
						$gcal_ids[] = $app->gcal_ID;
					}

					if ( in_array( $app->worker, $processed_workers ) ) {
						// We have added this APP to its worker's calendar
						continue;
					}

					$this->sync_appointment( $app );

				}

				/** @var Google_Service_Calendar_Event $event */
				foreach ( $events as $event ) {
					$event_id = $event->getID();
					if ( in_array( $event_id, $gcal_ids ) ) {
						// Already processed
						continue;
					}

					//$importer->import_event( $event );
				}
				$this->add_appointments_hooks();
			}
		}

	}

	/**
	 * @param Appointments_Appointment $app
	 */
	private function sync_appointment( $app, $worker_id = 0 ) {
		if ( $app->gcal_ID ) {
			if ( 'removed' === $app->status ) {
				$this->delete_event( $app->ID );

			}
			else {
				$this->update_event( $app->ID );
			}
		}
		elseif ( 'removed' != $app->status ) {
			$this->insert_event( $app->ID );
		}
	}

	public function is_connected() {
		$access_token = json_decode( $this->api_manager->get_access_token() );
		if ( ! $access_token ) {
			return false;
		}

		$options = appointments_get_options();

		if ( ! $this->worker_id && empty( $options['gcal_access_code'] ) ) {
			$options['gcal_access_code'] = '';
			appointments_update_options( $options );
			return false;
		}

		if ( empty( $options['gcal_client_id'] ) || empty( $options['gcal_client_secret'] ) ) {
			// No client secret and no client ID, why do we have a token then?
			$this->api_manager->set_access_token('{"access_token":0}');
			$options['gcal_token'] = '';
			$options['gcal_client_id'] = '';
			$options['gcal_client_secret'] = '';
			$options['gcal_access_code'] = '';
			appointments_update_options( $options );
			return false;
		}

		if ( ( ! isset( $access_token->access_token ) ) || ( isset( $access_token->access_token ) && ! $access_token->access_token ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Cast an Appointments_Appointment to a Google Event format
	 *
	 * @param $app_id
	 *
	 * @return Google_Service_Calendar_Event|bool
	 */
	public function appointment_to_gcal_event( $app_id ) {
		global $appointments;

		$app = appointments_get_appointment( $app_id );
		if ( ! $app ) {
			return false;
		}

		$event = new Google_Service_Calendar_Event();

		$options = appointments_get_options();

		// Event summary
		$summary = apply_filters(
			'app-gcal-set_summary',
			$appointments->_replace(
				$this->get_summary(),
				$app->name,
				$appointments->get_service_name( $app->service ),
				appointments_get_worker_name( $app->worker ),
				$app->start,
				$app->price,
				$appointments->get_deposit( $app->price ),
				$app->phone,
				$app->note,
				$app->address,
				$app->email,
				$app->city
			),
			$app
		);

		// Event description
		$description = apply_filters(
			'app-gcal-set_description',
			$appointments->_replace(
				$this->get_description(),
				$app->name,
				$appointments->get_service_name($app->service),
				appointments_get_worker_name($app->worker),
				$app->start,
				$app->price,
				$appointments->get_deposit( $app->price ),
				$app->phone,
				$app->note,
				$app->address,
				$app->email,
				$app->city
			),
			$app
		);

		// Location
		if ( isset( $options["gcal_location"] ) && '' != trim( $options["gcal_location"] ) ) {
			$location = str_replace( array( 'ADDRESS', 'CITY' ), array(
				$app->address,
				$app->city
			), $options["gcal_location"] );
		} else {
			$location = get_bloginfo( 'description' );
		}

		// Dates
		$start = new Google_Service_Calendar_EventDateTime();
		$start->setDateTime( $app->get_start_gmt_date( "Y-m-d\TH:i:s\Z" ) );
		$end = new Google_Service_Calendar_EventDateTime();
		$end->setDateTime( $app->get_end_gmt_date( "Y-m-d\TH:i:s\Z" ) );

		// Email
		$email = $app->get_email();

		// The first atendee will be the one with this email
		$attendee1 = new Google_Service_Calendar_EventAttendee();
		$attendee1->setEmail( $email );
		$attendees = array( $attendee1 );

		$event->setSummary( $summary );
		$event->setAttendees( $attendees );
		$event->setLocation( $location );
		$event->setStart( $start );
		$event->setEnd( $end );
		$event->setDescription( $description );

		// Alright, now deal with event sequencing
		if ( ! empty( $app->gcal_ID ) ) {
			$tmp = $this->api_manager->get_event( $app->gcal_ID );

			if ( ! is_wp_error( $tmp ) ) {
				if ( is_object( $tmp ) && ! empty( $tmp->sequence ) ) {
					$event->setSequence( $tmp->sequence );
				}
				elseif ( is_array( $tmp ) && ! empty( $tmp['sequence'] ) ) {
					$event->setSequence( $tmp['sequence'] );
				}
			}
		}

		return $event;
	}

	/**
	 * Sometimes Google will refresh the token.
	 * If so, we'll save it
	 */
	public function save_new_token() {
		$current_token = $this->api_manager->get_access_token();
		if ( ! $current_token ) {
			return;
		}

		$options = appointments_get_options();
		if ( $options['gcal_token'] != $current_token ) {
			$options['gcal_token'] = $current_token;
			appointments_update_options( $options );
		}
	}


	public function export_batch() {
		include_once( 'gcal/class-app-gcal-importer.php' );
		$importer = new Appointments_Google_Calendar_Importer( $this );
		$offset = absint( $_POST['offset'] );
		$offset = $importer->export( $offset );

		if ( false === $offset ) {
			// Finished
			wp_send_json_success();
		}

		wp_send_json_error( array( 'offset' => $offset ) );
	}

	public function import() {
		if ( 'sync' != $this->get_api_mode() ) {
			wp_send_json( array( 'message' => 'Error' ) );
		}

		include_once( 'gcal/class-app-gcal-importer.php' );
		$importer = new Appointments_Google_Calendar_Importer( $this );
		$this->remove_appointments_hooks();
		$results = $importer->import();
		$this->add_appointments_hooks();

		if ( is_wp_error( $results ) ) {
			wp_send_json( array( 'message' => $results->get_error_message() ) );
		}

		if ( $this->workers_allowed() ) {
			$workers = appointments_get_workers();
			foreach ( $workers as $worker ) {
				$switched = $this->switch_to_worker( $worker->ID );
				if ( $switched ) {
					$worker_results = $importer->import( $worker->ID );
					if ( ! is_wp_error( $results ) ) {
						$results['inserted'] += $worker_results['inserted'];
						$results['updated'] += $worker_results['updated'];
						$results['deleted'] += $worker_results['deleted'];
					}
					$this->restore_to_default();
				}

			}
		}


		wp_send_json( array( 'message' => sprintf( __( '%d updated, %d new inserted and %d deleted', 'appointments' ), $results['updated'], $results['inserted'], $results['deleted'] ) ) );
	}




	function get_apps_to_export_count() {
		$apps_count = appointments_count_appointments();
		$count = 0;
		foreach ( $this->get_syncable_status() as $status ) {
			$count += $apps_count[ $status ];
		}

		return $count;
	}




	/**
	 * Return GCal API mode (none, app2gcal or sync )
	 *
	 * @return string
	 */
	function get_api_mode() {
		return $this->api_mode;
	}

	public function get_access_code() {
		return $this->access_code;
	}

	public function get_api_scope() {
		$options = appointments_get_options();
		return isset( $options['gcal_api_scope'] ) ? $options['gcal_api_scope'] : 'all';
	}

	/**
	 * Check if workers are allowed to use their own calendar
	 *
	 * @return bool
	 */
	public function workers_allowed() {
		$options = appointments_get_options();
		return isset( $options['gcal_api_allow_worker'] ) && 'yes' === $options['gcal_api_allow_worker'];
	}

	private function _is_writable_mode() {
		$mode = $this->get_api_mode();
		return ! in_array( $mode, array( 'gcal2app', 'none' ) );
	}

	public function get_syncable_status () {
		return apply_filters( 'app-gcal-syncable_status', array( 'paid', 'confirmed' ) );
	}

	public function is_syncable_status( $status ) {
		$syncable_status = $this->get_syncable_status();
		return in_array( $status, $syncable_status );
	}

	public function switch_to_worker( $worker_id, $check_connection = true ) {
		if ( ! $this->workers_allowed() ) {
			return false;
		}

		if ( ! $this->is_connected() ) {
			return false;
		}

		$worker = appointments_get_worker( $worker_id );
		if ( ! $worker ) {
			return false;
		}

		$this->worker_id = $worker->ID;

		$worker_api_mode = get_user_meta( $worker_id, 'app_api_mode', true );
		if ( ! $worker_api_mode ) {
			$worker_api_mode = 'none';
		}

		$this->access_code = get_user_meta( $worker_id, 'app_gcal_access_code', true );
		$worker_description = get_user_meta( $worker_id, 'app_gcal_description', true );
		$this->description = $worker_description;

		$worker_summary = get_user_meta( $worker_id, 'app_gcal_summary', true );
		$this->summary = $worker_summary;

		// Set the API Mode
		$this->api_mode = $worker_api_mode;
		$this->api_manager->switch_to_worker( $worker_id );

		if ( $check_connection && ! $this->is_connected() ) {
			$this->restore_to_default();
			return false;
		}

		return true;
	}

	public function restore_to_default() {
		$options = appointments_get_options();

		// Set the API Mode
		$this->api_mode = $options['gcal_api_mode'];
		$this->description = $options['gcal_description'];
		$this->summary = $options['gcal_summary'];
		$this->api_manager->restore_to_default();
		$this->worker_id = false;

		return true;
	}


	/**
	 * Return GCal Summary (name of Event)
	 *
	 * @since 1.2.1
	 *
	 *
	 * @return string
	 */
	public function get_summary() {
		if ( empty( $this->summary ) ) {
			$this->summary = __('SERVICE Appointment','appointments');
		}
		return $this->summary;
	}

	public function set_summary( $summary ) {
		$this->summary = $summary;
	}

	/**
	 * Return GCal description
	 *
	 * @since 1.2.1
	 *
	 *
	 * @return string
	 */
	public function get_description() {
		if ( empty( $this->description ) ) {
			$this->description = __("Client Name: CLIENT\nService Name: SERVICE\nService Provider Name: SERVICE_PROVIDER\n", "appointments");
		}

		return $this->description;
	}

	public function set_description( $description ) {
		$this->description = $description;
	}



	// Appointments Hooks
	public function on_insert_appointment( $app_id ) {

		$app = appointments_get_appointment( $app_id );
		$worker = appointments_get_worker( $app->worker );

		if ( ( 'all' === $this->get_api_scope() ) || ( 'no_preference' === $this->get_api_scope() && ! $worker ) ) {
			// Insert in general calendar
			$this->insert_event( $app->ID );
		}

		if ( $this->workers_allowed() && $worker ) {
			// Maybe insert into worker calendar too
			$switched = $this->switch_to_worker( $worker->ID );
			if ( $switched ) {
				// The worker has a calendar assigned, let's insert
				$this->insert_event( $app_id );
				$this->restore_to_default();
			}
		}


	}

	public function on_delete_appointment( $app ) {
		if ( $app->gcal_ID ) {
			$this->delete_event( $app->gcal_ID );
		}
	}

	public function on_update_appointment( $app_id, $args, $old_app ) {
		$app = appointments_get_appointment( $app_id );

		if ( ! $app->gcal_ID ) {
			// No GCal reference, let's insert
			$this->on_insert_appointment( $app->ID );
			return;
		}

		$old_worker_id = $old_app->worker;
		$worker_id = $app->worker;
		$worker = appointments_get_worker( $worker_id );

		// Let's see first in which calendar is the event saved
		$saved_on = false;
		$event = $this->get_event( $app->ID );
		if ( ! $event ) {
			// Not in the general calendar
			// Maybe any worker?
			if ( $this->switch_to_worker( $worker->ID ) ) {
				// Check in the current worker
				$event = $this->get_event( $app->ID );
				$this->restore_to_default();
			}


			if ( ! $event && $worker_id != $old_worker_id && $this->switch_to_worker( $old_worker_id ) ) {
				// No? Then it must be in the old worker calendar
				// Let's delete it
				$saved_on = 'old-worker';
				$this->delete_event( $app->gcal_ID );
				$this->restore_to_default();
			}

			if ( $event ) {
				$saved_on = 'worker';
			}
		}
		else {
			$saved_on = 'general';
		}

		if ( ! $saved_on ) {
			// Is not saved anywhere
			// Let's insert it
			$this->on_insert_appointment( $app->ID );
			return;
		}


		if ( ( 'pending' == $app->status || 'removed' == $app->status || 'completed' == $app->status ) ) {
			if ( $worker ) {
				// Delete from worker
				if ( $this->switch_to_worker( $worker->ID ) ) {
					$this->delete_event( $app->gcal_ID );
					$this->restore_to_default();
				}
			}

			// And from general, why not try?
			$this->delete_event( $app->gcal_ID );
		}
		else {
			if ( ( 'all' === $this->get_api_scope() ) || ( 'no_preference' === $this->get_api_scope() && ! $worker ) ) {
				// Update general calendar
				$this->update_event( $app->ID );
			}

			// Try to update worker calendar too
			if ( $worker && $this->switch_to_worker( $worker->ID ) ) {
				if ( ! $this->get_event( $app->gcal_ID ) ) {
					$this->insert_event( $app->ID );
					$this->restore_to_default();
					return;
				}

				$this->update_event( $app_id );
				$this->restore_to_default();
			}

		}
	}


	// CRED functions
	public function get_event( $app_id ) {
		$app = appointments_get_appointment( $app_id );
		if ( ! $app ) {
			return false;
		}

		if ( ! $app->gcal_ID ) {
			return false;
		}

		$event = $this->api_manager->get_event( $app->gcal_ID );

		if ( ! is_wp_error( $event ) ) {
			return $event;
		}

		return false;

	}

	public function insert_event( $app_id ) {
		$app = appointments_get_appointment( $app_id );
		if ( ! $app ) {
			return false;
		}

		if ( ! $this->_is_writable_mode() ) {
			// We don't need to insert events on this mode
			return false;
		}

		if ( ! $this->is_syncable_status( $app->status ) ) {
			return false;
		}

		$event = $this->appointment_to_gcal_event( $app );

		$result = $this->api_manager->insert_event( $event );

		if ( is_wp_error( $result ) ) {
			return false;
		}
		else {
			$args = array( 'gcal_updated' => current_time( 'mysql' ), 'gcal_ID' => $result );
			$this->remove_appointments_hooks();
			appointments_update_appointment( $app_id, $args );
			$this->add_appointments_hooks();
		}

		return $result;
	}

	public function delete_event( $event_id ) {
		if ( ! $this->_is_writable_mode() ) {
			// We don't need to delete events on this mode
			return false;
		}

		$this->api_manager->delete_event( $event_id );

		$app = appointments_get_appointment_by_gcal_id( $event_id );
		if ( $app ) {
			$this->remove_appointments_hooks();
			appointments_update_appointment( $app->ID, array( 'gcal_ID' => '' ) );
			$this->add_appointments_hooks();
		}

		return true;

	}

	public function update_event( $app_id ) {
		$app = appointments_get_appointment( $app_id );
		if ( ! $app ) {
			return false;
		}

		if ( ! $this->_is_writable_mode() ) {
			// We don't need to insert events on this mode
			return false;
		}

		if ( ! $this->is_syncable_status( $app->status ) ) {
			return false;
		}

		$event_id = $app->gcal_ID;
		if ( ! $event_id ) {

			// Insert it!
			$result = $this->insert_event( $app_id );
			if ( ! $result ) {
				return false;
			}

			return true;
		}

		$event = $this->appointment_to_gcal_event( $app );
		$result = $this->api_manager->update_event( $event_id, $event );


		if ( is_wp_error( $result ) ) {
			return false;
		}
		else {
			$args = array( 'gcal_updated' => current_time( 'mysql' ) );
			$this->remove_appointments_hooks();
			appointments_update_appointment( $app_id, $args );
			$this->add_appointments_hooks();
		}

		return true;
	}


	public function get_events_list() {
		global $appointments;

		$current_time = current_time( 'timestamp' );
		$args = array(
			'timeMin'      => apply_filters( 'app_gcal_time_min', date( "c", $current_time ) ),
			'timeMax'      => apply_filters( 'app_gcal_time_max', date( "c", $current_time + ( 3600 * 24 * $appointments->get_app_limit() ) ) ),
			'singleEvents' => apply_filters( 'app_gcal_single_events', true ),
			'maxResults'   => apply_filters( 'app_gcal_max_results', APP_GCAL_MAX_RESULTS_LIMIT ),
			'orderBy'      => apply_filters( 'app_gcal_orderby', 'startTime' ),
		);

		$events = $this->api_manager->get_events_list( $args );

		return $events;
	}



}