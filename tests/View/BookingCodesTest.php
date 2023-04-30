<?php

namespace CommonsBooking\Tests\View;

use CommonsBooking\Model\Timeframe;
use CommonsBooking\Tests\Wordpress\CustomPostTypeTest;
use CommonsBooking\View\BookingCodes;
use CommonsBooking\Settings\Settings;


/**
  * @group email_bookingcodes
  */

class BookingCodesTest extends CustomPostTypeTest {

	protected const bookingDaysInAdvance = 35;

	protected const timeframeStart = 0;

	protected const timeframeEnd = 100;

	protected const bookingCodes=array("BOOKINGCODE1","BOOKINGCODE2","BOOKINGCODE3");

	protected $timeframeId;

	protected $userIDcbmanager;

	/* Tests if booking codes table is displayed and contains codes */
	public function testRenderTable() {
		$this->expectOutputRegex('/' . implode('|',self::bookingCodes) . '/');
		BookingCodes::renderTable($this->timeframeId);
	}

	/* Tests if booking direct email of booking codes contains codes */
	public function testEmailCodes() {

		delete_transient(\CommonsBooking\Model\BookingCode::ERROR_TYPE);
		reset_phpmailer_instance();
		$email = tests_retrieve_phpmailer_instance();

		try {
			BookingCodes::emailCodes($this->timeframeId, time(), strtotime("+10day"));
			$e_data = [];
		} catch ( \Exception $e ) {
			$e_data = json_decode( $e->getMessage(), true );
		}

		$this->assertFalse(get_transient(\CommonsBooking\Model\BookingCode::ERROR_TYPE));
		$this->assertNotEmpty( $e_data );
		$this->assertStringEndsWith('#email-booking-codes-list',$e_data['location']);
		$this->assertRegExp('/' . implode('|',self::bookingCodes) . '/',$email->get_sent()->body);
	}

	public static function on_wp_redirect($location, $status){
		throw new \Exception(
			json_encode(
				[
					'location' => $location,
					'status'   => $status,
				]
			)
		);	
	}

	protected function deleteCBOptions() {
		foreach ( wp_load_alloptions() as $option => $value ) {
			if ( strpos( $option, COMMONSBOOKING_PLUGIN_SLUG . '_options' ) === 0 ) {
				delete_option( $option );
			}
		}		
	}

	protected function setUp() {
		parent::setUp();
		//set default options for email templates
		\CommonsBooking\Wordpress\Options\AdminOptions::setOptionsDefaultValues();

		//set defined booking codes option
        Settings::updateOption( 'commonsbooking_options_bookingcodes', 'bookingcodes', implode(',',self::bookingCodes) );

		$now               = time();
		$this->timeframeId = $this->createTimeframe(
			$this->locationId,
			$this->itemId,
			strtotime( '+' . self::timeframeStart . ' days midnight', $now ),
			strtotime( '+' . self::timeframeEnd . ' days midnight', $now )
		);

		//force save_post action to generate booking codes
		$timeframePost=get_post($this->timeframeId);		
		do_action( 'save_post', $this->timeframeId, $timeframePost, true );
		
		//create and add CB LocationManager
		$userdata = array(
			'user_login' =>  'TestCBManager',
			'user_email'   =>  'TestCBManager@nowhere.com',
			'user_pass'  =>  'TestCBManager',
			'role' => \CommonsBooking\Plugin::$CB_MANAGER_ID,
		);		
		$this->userIDcbmanager = wp_insert_user( $userdata ) ;
		$timeframe=new Timeframe($this->timeframeId);
		update_post_meta( $timeframe->getLocation()->ID, COMMONSBOOKING_METABOX_PREFIX . 'location_admins', $this->userIDcbmanager );


		//setup the wp_redirect "mock"
		add_filter( 'wp_redirect', array( __CLASS__,'on_wp_redirect'), 1, 2 );

	}



	protected function tearDown() {
		remove_filter( 'wp_redirect', array( __CLASS__,'on_wp_redirect'), 1 );
		delete_transient(\CommonsBooking\Model\BookingCode::ERROR_TYPE);
		wp_delete_user($this->userIDcbmanager);
		$this->deleteCBOptions();
		parent::tearDown(); 

	}


}
