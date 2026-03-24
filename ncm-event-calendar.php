<?php
/**
 * Plugin Name: NCM Event Calendar (FullCalendar + TEC)
 * Description: Frontend calendar powered by FullCalendar, pulling events automatically from The Events Calendar.
 * Version: 1.0.0
 * Author: Neon Cactus Media
 * Author URI: https://neoncactusmedia.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class NCM_Event_Calendar {
	const VERSION   = '1.0.0';
	const REST_NS   = 'ncm-ec/v1';
	const SHORTCODE = 'ncm_event_calendar';

	public function __construct() {
		add_action( 'init', [ $this, 'register_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_dequeue_tec_assets' ], 999 );

        add_action( 'plugins_loaded', [ $this, 'maybe_apply_tec_slim_mode' ], 20 );
        add_action( 'admin_notices', [ $this, 'maybe_show_tec_missing_notice' ] );
        add_action('admin_enqueue_scripts', function($hook){
          if ($hook !== 'settings_page_ncm-event-calendar') return;
          wp_enqueue_style('dashicons');
        });

	}

	public function register_shortcode() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
	}

	public function register_assets() {
		// FullCalendar (CDN). You can bundle locally later if you prefer.
		wp_register_style(
			'ncm-fc',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css',
			[],
			self::VERSION
		);

		wp_register_script(
			'ncm-fc',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
			[],
			self::VERSION,
			true
		);

		// Your plugin CSS/JS
		wp_register_style(
			'ncm-ec',
			plugins_url( 'assets/ncm-ec.css', __FILE__ ),
			[ 'ncm-fc' ],
			self::VERSION
		);

		wp_register_script(
			'ncm-ec',
			plugins_url( 'assets/ncm-ec.js', __FILE__ ),
			[ 'ncm-fc' ],
			self::VERSION,
			true
		);
	}

	public function render_shortcode( $atts ) {
    	$source = get_option( 'ncm_ec_data_source', 'tec' );

    	if ( $source === 'tec' && ! $this->tec_is_active() ) {
    		// Don’t show visitors a scary message.
    		if ( current_user_can( 'manage_options' ) ) {
    			return '<div class="ncm-ec-notice ncm-ec-notice--warning" style="padding:12px;border:1px solid #e6e6e6;border-radius:10px;">
    				<strong>NCM Event Calendar:</strong> No events source found. Install/activate <em>The Events Calendar</em> (TEC),
    				or change the data source in <a href="/wp-admin/options-general.php?page=ncm-event-calendar">settings</a>.
    			</div>';
    		}
    
    		// For normal visitors, render nothing (or a “no events” empty state).
    		return '<div class="ncm-ec-empty"></div>';
    	}
	    
	    
		$atts = shortcode_atts(
			[
				'view'     => 'dayGridMonth', // dayGridMonth | timeGridWeek | timeGridDay | listMonth
				'firstday' => '0',            // 0 = Sunday
				'height'   => 'auto',
				'views'    => 'month,week,day,list', 
				'skin'     => 'default',
			],
			$atts,
			self::SHORTCODE
		);

		// Enqueue assets only when shortcode is used
		wp_enqueue_style( 'ncm-fc' );
		wp_enqueue_style( 'ncm-ec' );
		wp_enqueue_script( 'ncm-fc' );
		wp_enqueue_script( 'ncm-ec' );
		
		// ---- Skin / Look ---
		$skin = strtolower( sanitize_text_field( $atts['skin'] ) );
		if ( ! in_array( $skin, [ 'default', 'sugar' ], true ) ) {
			$skin = 'default';
		}

		// ---- Allowed views (per-calendar instance) ----
		$views_raw = is_string( $atts['views'] ) ? $atts['views'] : 'month,list';
		$views_arr = array_filter( array_map( 'trim', explode( ',', strtolower( $views_raw ) ) ) );

		// Only allow these tokens
		$allowed_tokens = [ 'month', 'week', 'day', 'list' ];
		$enabled_views  = array_values( array_intersect( $views_arr, $allowed_tokens ) );

		// Fallback if someone passes junk or empty
		if ( empty( $enabled_views ) ) {
		$enabled_views = [ 'month', 'list' ];
		}

		// Map tokens -> FullCalendar view types used in your JS
		$token_to_fc = [
		'month' => 'dayGridMonth',
		'week'  => 'timeGridWeek',
		'day'   => 'timeGridDay',
		'list'  => 'listMonth',
		];

		// Validate requested initial view against enabled views
		$requested_view = sanitize_text_field( $atts['view'] );
		$enabled_fc_views = array_map( function( $t ) use ( $token_to_fc ) {
		return $token_to_fc[ $t ];
		}, $enabled_views );

		if ( ! in_array( $requested_view, $enabled_fc_views, true ) ) {
		// Default to the first enabled view
		$requested_view = $enabled_fc_views[0];
		}


		$settings = [
			'restUrl'   => esc_url_raw( rest_url( self::REST_NS . '/events' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'initialView' => $requested_view,
			'enabledViews' => $enabled_views,
			'firstDay'  => (int) $atts['firstday'],
			'height'    => sanitize_text_field( $atts['height'] ),
			'hasTEC' => $this->tec_is_active(),
		];

		$uid = 'ncm-ec-' . wp_generate_uuid4();

		ob_start(); ?>
		<div class="ncm-ec ncm-ec--<?php echo esc_attr( $skin ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-ncm-ec="<?php echo esc_attr( wp_json_encode( $settings ) ); ?>">
			<?php if ( ! $settings['hasTEC'] ) : ?>
				<div class="ncm-ec-notice">
					The Events Calendar (tribe_events) was not detected. Please install/activate TEC.
				</div>
			<?php endif; ?>

			<div class="ncm-ec-calendar"></div>

			<!-- Popup -->
			<div class="ncm-ec-popup-overlay" hidden>
				<div class="ncm-ec-popup" role="dialog" aria-modal="true" aria-label="Event details">
					<button type="button" class="ncm-ec-popup-close" aria-label="Close">×</button>

					<div class="ncm-ec-popup-body-wrap">
						<div class="ncm-ec-popup-body">
							<div class="ncm-ec-popup-image" hidden>
								<img src="" alt="">
							</div>

							<div class="ncm-ec-popup-content">
								<ul class="ncm-ec-meta">
									<li class="ncm-ec-meta-time">
										<div class="ncm-ec-icon">
											<!-- clock icon -->
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="16" height="16" aria-hidden="true">
												<path d="M256 0C114.8 0 0 114.8 0 256s114.8 256 256 256 256-114.8 256-256S397.2 0 256 0zm121.8 388.4c-4.2 4.2-9.6 6.3-15.1 6.3s-10.9-2.1-15.1-6.3L240.9 281.8c-4-4-6.2-9.4-6.2-15.1V128c0-11.8 9.6-21.3 21.3-21.3s21.3 9.5 21.3 21.3v129.8l100.4 100.4c8.4 8.4 8.4 21.9 0 30.2z"/>
											</svg>
										</div>
										<div class="ncm-ec-meta-content">
											<span class="ncm-ec-meta-title ncm-ec-date-label">Date</span>
											<span class="ncm-ec-meta-value ncm-ec-time"></span>
										</div>
									</li>

									<li class="ncm-ec-meta-org">
										<div class="ncm-ec-icon">
											<!-- user icon -->
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
												<path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5zm0 2c-4.4 0-8 2.2-8 5v1h16v-1c0-2.8-3.6-5-8-5z"/>
											</svg>
										</div>
										<div class="ncm-ec-meta-content">
											<span class="ncm-ec-meta-title">Organizer</span>
											<span class="ncm-ec-meta-value ncm-ec-organizer"></span>
										</div>
									</li>

									<li class="ncm-ec-meta-loc">
										<div class="ncm-ec-icon">
											<!-- pin icon -->
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 384 512" width="16" height="16" aria-hidden="true">
												<path d="M168 0C75.1 0 0 75.1 0 168c0 87.3 135.5 302.7 153.8 331.3c7.5 11.8 24.9 11.8 32.4 0C248.5 470.7 384 255.3 384 168C384 75.1 308.9 0 216 0H168zm48 256a88 88 0 1 1 0-176a88 88 0 1 1 0 176z"/>
											</svg>
										</div>
										<div class="ncm-ec-meta-content">
											<span class="ncm-ec-meta-title">Location</span>
											<span class="ncm-ec-meta-value ncm-ec-location"></span>
										</div>
									</li>
								</ul>

								<h3 class="ncm-ec-title"></h3>
								<div class="ncm-ec-desc"></div>

								<div class="ncm-ec-actions" hidden>
									<a class="ncm-ec-link" href="" target="_blank" rel="noopener noreferrer"></a>
								</div>
							</div><!-- /.ncm-ec-popup-content -->
						</div><!-- /.ncm-ec-popup-body -->
					</div><!-- /.ncm-ec-popup-body-wrap -->
				</div><!-- /.ncm-ec-popup -->
			</div><!-- /.ncm-ec-popup-overlay -->
		</div>
		<?php
		return ob_get_clean();
	}

	public function register_rest_routes() {
		register_rest_route(
			self::REST_NS,
			'/events',
			[
				'methods'  => 'GET',
				'callback' => [ $this, 'rest_get_events' ],
				'permission_callback' => '__return_true',
				'args' => [
					'start' => [ 'required' => true ],
					'end'   => [ 'required' => true ],
				],
			]
		);
	}

	public function rest_get_events( WP_REST_Request $request ) {
    	$source = get_option( 'ncm_ec_data_source', 'tec' );
    
    	// Only guard when using TEC as the data source
    	if ( $source === 'tec' && ! $this->tec_is_active() ) {
    		return rest_ensure_response( [] );
    	}

		$start = sanitize_text_field( $request->get_param( 'start' ) ); // ISO string
		$end   = sanitize_text_field( $request->get_param( 'end' ) );

		// Convert to MySQL DATETIME for TEC meta compare
		$start_dt = gmdate( 'Y-m-d H:i:s', strtotime( $start ) );
		$end_dt   = gmdate( 'Y-m-d H:i:s', strtotime( $end ) );

		$q = new WP_Query([
			'post_type'      => 'tribe_events',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'orderby'        => 'meta_value',
			'meta_key'       => '_EventStartDate',
			'order'          => 'ASC',
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => '_EventStartDate',
					'value'   => $end_dt,
					'compare' => '<=',
					'type'    => 'DATETIME',
				],
				[
					'key'     => '_EventEndDate',
					'value'   => $start_dt,
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
			],
		]);

		$events = [];

		foreach ( $q->posts as $post ) {
			$event_id = $post->ID;

			$event_start = get_post_meta( $event_id, '_EventStartDate', true );
			$event_end   = get_post_meta( $event_id, '_EventEndDate', true );
			$all_day     = get_post_meta( $event_id, '_EventAllDay', true );

			// Featured image
			$image = get_the_post_thumbnail_url( $event_id, 'large' );
			if ( ! $image ) $image = '';

			// Organizer / Venue (use TEC helpers if available)
			$organizer = '';
			$location  = '';

			if ( function_exists( 'tribe_get_organizer' ) ) {
				$organizer = (string) tribe_get_organizer( $event_id );
			}

			if ( function_exists( 'tribe_get_venue' ) ) {
				$location = (string) tribe_get_venue( $event_id );
			}

			// Build a map link (best-effort)
			$map_url = '';
			if ( function_exists( 'tribe_get_map_link' ) ) {
				$map_url = (string) tribe_get_map_link( $event_id );
			} else {
				$venue_address = get_post_meta( $event_id, '_EventVenueAddress', true );
				if ( $venue_address ) {
					$map_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $venue_address );
				}
			}

			// Friendly date label for popup
			$date_label = '';
			if ( $event_start ) {
				$date_label = wp_date( 'F j, Y', strtotime( $event_start ) );
			}

			$events[] = [
				'id'    => (string) $event_id,
				'title' => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
				'start' => $event_start ? mysql2date( 'c', $event_start ) : null,
				'end'   => $event_end ? mysql2date( 'c', $event_end ) : null,
				'allDay'=> ( $all_day === 'yes' || $all_day === '1' || $all_day === 1 ),
				'url'   => get_permalink( $event_id ),
				'extendedProps' => [
					'image'     => $image,
					'organizer' => html_entity_decode( $organizer, ENT_QUOTES, 'UTF-8' ),
					'location'  => html_entity_decode( $location, ENT_QUOTES, 'UTF-8' ),
					'mapUrl'    => $map_url,
					'dateLabel' => $date_label,
					'desc'      => wp_kses_post( apply_filters( 'the_content', $post->post_content ) ),
				],
			];
		}

		wp_reset_postdata();

		return rest_ensure_response( $events );
	}
	
	public function add_settings_page() {
    	add_options_page(
    		'NCM Event Calendar',
    		'NCM Event Calendar',
    		'manage_options',
    		'ncm-event-calendar',
    		[ $this, 'render_settings_page' ]
    	);
    }
    
    public function register_settings() {
    	register_setting( 'ncm_ec_settings', 'ncm_ec_slim_mode', [
    		'type' => 'boolean',
    		'sanitize_callback' => function( $v ) { return (bool) $v; },
    		'default' => false,
    	] );
    
    	register_setting( 'ncm_ec_settings', 'ncm_ec_hide_venues_organizers', [
    		'type' => 'boolean',
    		'sanitize_callback' => function( $v ) { return (bool) $v; },
    		'default' => false,
    	] );
    
    	register_setting( 'ncm_ec_settings', 'ncm_ec_dequeue_tec_assets', [
    		'type' => 'boolean',
    		'sanitize_callback' => function( $v ) { return (bool) $v; },
    		'default' => true,
    	] );
    	
    	register_setting( 'ncm_ec_settings', 'ncm_ec_data_source', [
        	'type' => 'string',
        	'sanitize_callback' => function( $v ) {
        		$v = is_string($v) ? $v : 'tec';
        		return in_array( $v, [ 'tec', 'acf' ], true ) ? $v : 'tec';
        	},
        	'default' => 'tec',
        ] );

    }
    
    public function render_settings_page() {
    	$source  = get_option( 'ncm_ec_data_source', 'tec' );
    	$has_tec = $this->tec_is_active();
    
    	// Tabs config
    	$tabs = [
    		'source'  => 'Data Source',
    		'slim'    => 'TEC Slim',
    		'assets'  => 'Assets',
    		'advanced'=> 'Advanced',
    	];
    
    	?>
    	<div class="wrap">
    		<h1>NCM Event Calendar</h1>
    
    		<?php if ( $source === 'tec' ) : ?>
    			<div class="notice notice-info" style="padding:12px 12px 6px;">
    				<p style="margin:0 0 6px;">
    					<strong>Status:</strong> Data source is <strong>The Events Calendar (TEC)</strong><br>
    					<strong>TEC detected:</strong> <?php echo $has_tec ? 'Yes ✅' : 'No ❌'; ?>
    				</p>
    
    				<?php
    				if ( ! $has_tec && ( current_user_can( 'install_plugins' ) || current_user_can( 'activate_plugins' ) ) ) {
    					$btn = '';
    					if ( $this->tec_is_installed() ) {
    						if ( current_user_can( 'activate_plugins' ) ) {
    							$btn = '<a class="button button-primary" href="' . esc_url( $this->tec_activate_url() ) . '">Activate The Events Calendar</a>';
    						}
    					} else {
    						if ( current_user_can( 'install_plugins' ) ) {
    							$btn = '<a class="button button-primary" href="' . esc_url( $this->tec_install_url() ) . '">Install The Events Calendar</a>';
    						}
    					}
    					if ( $btn ) {
    						echo '<p style="margin:0 0 6px;">' . $btn . '</p>';
    					}
    				}
    				?>
    			</div>
    		<?php endif; ?>
    
    		<h2 class="nav-tab-wrapper" style="margin-top:16px;">
    			<?php foreach ( $tabs as $slug => $label ) : ?>
    				<a href="#ncm-ec-tab-<?php echo esc_attr( $slug ); ?>"
    				   class="nav-tab"
    				   data-ncm-ec-tab="<?php echo esc_attr( $slug ); ?>">
    					<?php echo esc_html( $label ); ?>
    				</a>
    			<?php endforeach; ?>
    		</h2>
    
    		<form method="post" action="options.php">
    			<?php settings_fields( 'ncm_ec_settings' ); ?>
    
    			<?php
                    $disabled = ( $source !== 'tec' );
                    $disabled_attr = $disabled ? ' disabled' : '';
                ?>

    
    			<!-- TAB: Data Source -->
    			<div class="ncm-ec-tab-panel" id="ncm-ec-tab-source" role="tabpanel">
    				<table class="form-table" role="presentation">
    					<tr>
    						<th scope="row">
    							<label for="ncm_ec_data_source">Data source</label>
    						</th>
    						<td>
    							<select id="ncm_ec_data_source" name="ncm_ec_data_source">
    								<option value="tec" <?php selected( $source, 'tec' ); ?>>The Events Calendar (TEC)</option>
    								<option value="acf" <?php selected( $source, 'acf' ); ?>>ACF / Custom Events (coming soon)</option>
    							</select>
    							<p class="description">
    								Choose where calendar events come from.
    								<?php if ( $source === 'tec' && ! $has_tec ) : ?>
    									<br><strong>TEC is not active</strong>, so your calendar will show no events until it’s installed/activated.
    								<?php endif; ?>
    							</p>
    
    							<hr style="margin:18px 0;">
    
    							<p>
                                	<strong>Shortcode:</strong>
                                </p>
                                
                                <div class="ncm-ec-shortcode-wrap">
                                	<code id="ncm-ec-shortcode">[ncm_event_calendar]</code>
                                	<button type="button"
                                		class="button ncm-ec-copy-btn"
                                		data-copy-target="ncm-ec-shortcode"
                                		aria-label="Copy shortcode">
                                		<span class="dashicons dashicons-clipboard"></span>
                                		Copy
                                	</button>
                                </div>
    							<p class="description">
    								Use attributes like: <code>[ncm_event_calendar view="timeGridWeek" firstday="1"]</code>
    							</p>
    						</td>
    					</tr>
    				</table>
    			</div>
    
    			<!-- TAB: TEC Slim -->
    			<div class="ncm-ec-tab-panel" id="ncm-ec-tab-slim" role="tabpanel" hidden>
    				<table class="form-table" role="presentation">
    					<tr>
    						<th scope="row">TEC Slim Mode</th>
    						<td>
    							<label>
    								<input type="checkbox" name="ncm_ec_slim_mode" value="1"
    									<?php checked( 1, get_option( 'ncm_ec_slim_mode' ) ); ?>
    									<?php echo $disabled_attr; ?>

    								>
    								Hide most TEC menus/settings and keep only event creation/editing.
    							</label>
    							<p class="description">
    								Best-effort; TEC screen slugs vary by version/add-ons.
    							</p>
    							<?php if ( $disabled ) : ?>
    								<p class="description"><em>Disabled because your data source is not TEC.</em></p>
    							<?php endif; ?>
    						</td>
    					</tr>
    
    					<tr>
    						<th scope="row">Hide Venues &amp; Organizers</th>
    						<td>
    							<label>
    								<input type="checkbox" name="ncm_ec_hide_venues_organizers" value="1"
    									<?php checked( 1, get_option( 'ncm_ec_hide_venues_organizers' ) ); ?>
    									<?php echo $disabled_attr; ?>
    								>
    								Remove Venues/Organizers UI.
    							</label>
    							<p class="description">
    								Events still work; your popup can show venue/organizer as plain text if needed.
    							</p>
    						</td>
    					</tr>
    				</table>
    			</div>
    
    			<!-- TAB: Assets -->
    			<div class="ncm-ec-tab-panel" id="ncm-ec-tab-assets" role="tabpanel" hidden>
    				<table class="form-table" role="presentation">
    					<tr>
    						<th scope="row">Dequeue TEC frontend assets</th>
    						<td>
    							<label>
    								<input type="checkbox" name="ncm_ec_dequeue_tec_assets" value="1"
    									<?php checked( 1, get_option( 'ncm_ec_dequeue_tec_assets', 1 ) ); ?>
    									<?php echo $disabled_attr; ?>

    								>
    								Stop TEC CSS/JS from loading on pages using your calendar shortcode.
    							</label>
    							<p class="description">
    								Only runs when the shortcode exists on the current singular page.
    							</p>
    						</td>
    					</tr>
    				</table>
    			</div>
    
    			<!-- TAB: Advanced -->
    			<div class="ncm-ec-tab-panel" id="ncm-ec-tab-advanced" role="tabpanel" hidden>
    				<table class="form-table" role="presentation">
    					<tr>
    						<th scope="row">Notes</th>
    						<td>
    							<ul style="margin:0; padding-left:18px;">
    								<li>REST endpoint: <code><?php echo esc_html( rest_url( self::REST_NS . '/events' ) ); ?></code></li>
    								<li>If you use caching, exclude the REST route if needed.</li>
    							</ul>
    						</td>
    					</tr>
    				</table>
    			</div>
    
    			<?php submit_button( 'Save settings' ); ?>
    		</form>
    	</div>
    
    	<style>
    		/* Keep it tidy */
    		.ncm-ec-tab-panel { background:#fff; border:1px solid #dcdcde; border-top:none; padding: 12px 16px; }
    		.ncm-ec-tab-panel .form-table { margin-top: 0; }
    		.ncm-ec-shortcode-wrap {
            	display: inline-flex;
            	align-items: center;
            	gap: 8px;
            	margin-top: 6px;
            }
            .ncm-ec-copy-btn .dashicons {
            	margin-right: 4px;
            	line-height: 1.4;
            }
            .ncm-ec-copy-btn.is-copied {
            	background: #46b450;
            	border-color: #46b450;
            	color: #fff;
            }

    	</style>
    
    	<script>
    	(function() {
    		const tabs = document.querySelectorAll('[data-ncm-ec-tab]');
    		const panels = document.querySelectorAll('.ncm-ec-tab-panel');
    		if (!tabs.length || !panels.length) return;
    
    		function activate(slug, pushState = true) {
    			tabs.forEach(t => t.classList.toggle('nav-tab-active', t.dataset.ncmEcTab === slug));
    			panels.forEach(p => p.hidden = (p.id !== 'ncm-ec-tab-' + slug));
    			if (pushState) {
    				const url = new URL(window.location.href);
    				url.hash = 'ncm-ec-tab-' + slug;
    				window.history.replaceState({}, '', url.toString());
    			}
    		}
    
    		tabs.forEach(tab => {
    			tab.addEventListener('click', (e) => {
    				e.preventDefault();
    				activate(tab.dataset.ncmEcTab);
    			});
    		});
    
    		// Load from hash (or default)
    		const hash = window.location.hash || '';
    		const match = hash.match(/^#ncm-ec-tab-(.+)$/);
    		const initial = match ? match[1] : 'source';
    		activate(initial, false);
    		
	    	// Copy shortcode button
            document.querySelectorAll('.ncm-ec-copy-btn').forEach(btn => {
            	btn.addEventListener('click', async () => {
            		const id = btn.getAttribute('data-copy-target');
            		const el = document.getElementById(id);
            		if (!el) return;
            
            		try {
            			await navigator.clipboard.writeText(el.textContent.trim());
            
            			const original = btn.innerHTML;
            			btn.classList.add('is-copied');
            			btn.innerHTML = '✓ Copied';
            
            			setTimeout(() => {
            				btn.classList.remove('is-copied');
            				btn.innerHTML = original;
            			}, 1500);
            		} catch (err) {
            			alert('Could not copy shortcode');
            		}
            	});
            });
    	})();


    	</script>
    	<?php
    }


    
    public function maybe_apply_tec_slim_mode() {
    	if ( ! get_option( 'ncm_ec_slim_mode' ) ) {
    		return;
    	}
    
    	// Hide admin bar Events menu item (TEC has constant, but do it safely here too)
    	add_action( 'admin_bar_menu', function( $bar ) {
    		$bar->remove_node( 'tribe-events' );
    	}, 100 );
    
    	// Remove submenu pages (best-effort; handles vary by TEC version/addons)
    	add_action( 'admin_menu', function() {
    		// Main "Events" menu stays because it's the CPT list/edit screens
    		// Remove common subpages
    		remove_submenu_page( 'edit.php?post_type=tribe_events', 'tribe-common' ); // sometimes settings hub
    		remove_submenu_page( 'edit.php?post_type=tribe_events', 'tribe_events_calendar' ); // sometimes "Calendar" view
    		remove_submenu_page( 'edit.php?post_type=tribe_events', 'tribe-common-settings' );
    		remove_submenu_page( 'edit.php?post_type=tribe_events', 'tribe-common' );
    		remove_submenu_page( 'edit.php?post_type=tribe_events', 'tribe-help' );
    		remove_submenu_page( 'edit.php?post_type=tribe_events', 'tribe-common-licenses' );
    	}, 999 );
    
    	// Optionally hide Venues & Organizers menus
    	if ( get_option( 'ncm_ec_hide_venues_organizers' ) ) {
    		add_action( 'admin_menu', function() {
    			remove_submenu_page( 'edit.php?post_type=tribe_events', 'edit.php?post_type=tribe_venue' );
    			remove_submenu_page( 'edit.php?post_type=tribe_events', 'edit.php?post_type=tribe_organizer' );
    		}, 999 );
    	}
    
    	// Block direct access to TEC settings screens if someone knows URLs
    	add_action( 'current_screen', function( $screen ) {
    		$id = $screen ? $screen->id : '';
    		// This is intentionally broad (TEC screen IDs vary)
    		if ( is_string( $id ) && ( strpos( $id, 'tribe-common' ) !== false || strpos( $id, 'tribe_events_page' ) !== false ) ) {
    			wp_die( 'This area is disabled by NCM Event Calendar Slim Mode.' );
    		}
    	});
    }


    private function tec_is_active(): bool {
    	// TEC defines TRIBE_EVENTS_FILE and a few classes/functions, but keep it flexible.
    	return defined( 'TRIBE_EVENTS_FILE' )
    		|| class_exists( 'Tribe__Events__Main' )
    		|| function_exists( 'tribe_is_event_query' );
    }
    public function maybe_show_tec_missing_notice() {
    	$source = get_option( 'ncm_ec_data_source', 'tec' );
    	if ( $source !== 'tec' ) return;
    
    	if ( $this->tec_is_active() ) return;
    
    	if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'activate_plugins' ) ) return;
    
    	if ( ! function_exists( 'get_plugins' ) ) {
    		require_once ABSPATH . 'wp-admin/includes/plugin.php';
    	}
    
    	$btn = '';
    	if ( $this->tec_is_installed() ) {
    		if ( current_user_can( 'activate_plugins' ) ) {
    			$btn = ' <a class="button button-primary" href="' . esc_url( $this->tec_activate_url() ) . '">Activate The Events Calendar</a>';
    		}
    	} else {
    		if ( current_user_can( 'install_plugins' ) ) {
    			$btn = ' <a class="button button-primary" href="' . esc_url( $this->tec_install_url() ) . '">Install The Events Calendar</a>';
    		}
    	}
    
    	echo '<div class="notice notice-warning"><p>'
    		. '<strong>NCM Event Calendar:</strong> Calendar is set to pull events from <em>The Events Calendar</em>, but it isn’t available.'
    		. $btn
    		. ' <a class="button button-secondary" href="' . esc_url( admin_url( 'options-general.php?page=ncm-event-calendar' ) ) . '">Open settings</a>'
    		. '</p></div>';
    }


    public function maybe_dequeue_tec_assets() {
    	if ( ! get_option( 'ncm_ec_dequeue_tec_assets', 1 ) ) return;
    
    	// Only if the current post content has the shortcode
    	if ( ! is_singular() ) return;
    
    	$post = get_post();
    	if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) return;
    
    	// Best-effort TEC handles
    	wp_dequeue_style( 'tribe-events-views-v2-full' );
    	wp_dequeue_style( 'tribe-common-full-style' );
    	wp_dequeue_script( 'tribe-events-views-v2' );
    	wp_dequeue_script( 'tribe-common' );
    }


    private function tec_plugin_file(): string {
    	// Main plugin file slug for TEC (free version)
    	return 'the-events-calendar/the-events-calendar.php';
    }
    
    private function tec_is_installed(): bool {
    	$plugin_file = $this->tec_plugin_file();
    	$installed = get_plugins(); // requires plugin.php
    	return isset( $installed[ $plugin_file ] );
    }
    
    private function tec_install_url(): string {
    	return wp_nonce_url(
    		self_admin_url( 'update.php?action=install-plugin&plugin=the-events-calendar' ),
    		'install-plugin_the-events-calendar'
    	);
    }
    
    private function tec_activate_url(): string {
    	$plugin_file = $this->tec_plugin_file();
    	return wp_nonce_url(
    		self_admin_url( 'plugins.php?action=activate&plugin=' . urlencode( $plugin_file ) ),
    		'activate-plugin_' . $plugin_file
    	);
    }


}

new NCM_Event_Calendar();
