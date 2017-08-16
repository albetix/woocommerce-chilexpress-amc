<?php
if (!defined('ABSPATH')) {
	die();
}

/**
 * Chilexpress Shipping Class for WooCommerce
 */
function whq_wcchp_init_class() {
	if ( ! class_exists('WC_Payment_Gateway') ) {
		return;
	}

	if ( ! class_exists( 'WC_WHQ_Chilexpress_Shipping' ) ) {
		class WC_WHQ_Chilexpress_Shipping extends WC_Shipping_Method {

			/**
			 * Constructor shipping class
			 *
			 * @access public
			 * @return void
			 */
			public function __construct(){
				$this->id                 = 'chilexpress';
				$this->method_title       = __( 'Chilexpress', 'whq-wcchp' );
				$this->method_description = __( 'Utiliza la API de Chilexpress para el cálculo automático de costos de envío. Sugerencias y reporte de errores en <a href="https://github.com/whooohq/whq-woocommerce-chilexpress-shipping/issues" target="_blank">GitHub</a>.', 'whq-wcchp' );

				// Load the settings.
				$this->init_form_fields();
				$this->init_settings();

				// Define user set variables
				$this->enabled         = $this->get_option( 'enabled' );
				$this->title           = $this->get_option( 'title' );
				$this->shipping_origin = $this->get_option( 'shipping_origin' );
				$this->shipments_types = $this->get_option( 'shipments_types' );
				$this->locations_cache = $this->get_option( 'locations_cache' );
				$this->extra_wrapper   = $this->get_option( 'extra_wrapper' );
				$this->soap_login      = $this->get_option( 'soap_login' );
				$this->soap_password   = $this->get_option( 'soap_password' );
				$this->availability    = true;

				// Save settings in admin if you have any defined
				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			/**
			 * Init settings
			 *
			 * @access public
			 * @return void
			 */
			public static function init() {
				// Load the settings API
				$this->init_form_fields(); // This is part of the settings API. Override the method to add your own settings
				$this->init_settings(); // This is part of the settings API. Loads settings you previously init.

				// Save settings in admin if you have any defined
				add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			/**
			 * Form fields
			 *
			 * @access public
			 * @return void
			 */
			public function init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
						'title'   => __( 'Activar/Desactivar', 'whq-wcchp' ),
						'type'    => 'checkbox',
						'label'   => __( 'Habilitar envíos vía Chilexpress', 'whq-wcchp' ),
						'default' => 'yes',
					),
					'title' => array(
						'title'       => __( 'Título del método de envío', 'whq-wcchp' ),
						'type'        => 'text',
						'description' => __( 'El título del método de envío que el usuario verá en la página de checkout', 'whq-wcchp' ),
						'default'     => __( 'Envío vía Chilexpress', 'whq-wcchp' ),
					),
					'shipping_origin' => array(
						'title'       => __( 'Origen de los envios', 'whq-wcchp' ),
						'type'        => 'select',
						'description' => __( 'Ciudad/Localidad de origen, desde donde se realiza el envío', 'whq-wcchp' ),
						'options'     => $this->get_cities(),
					),
					'shipments_types' => array(
						'title'       => __( 'Tipos de envíos soportados', 'whq-wcchp' ),
						'type'        => 'multiselect',
						'description' => __( 'Selecciona los tipos de envíos a soportar.<br/>Considera que dependiendo de la ubicación de origen de tus envíos, algunas localidades extremas podrían no contar con un tipo de envío normal (día hábil siguiente), y solo tener disponible envío al tercer día, por lo que lo recomendado es que selecciones al menos "día hábil siguiente", "día hábil subsiguiente" y "tercer día".<br/>Ten presente que los envíos Ultra Rápidos y Overnight están disponibles solo en ciertas ciudades de origen y para algunos destinos.<br/>También debes tener en cuenta que el envío Ultra Rápido debería ser despachado inmediatamente por tu tienda para cumplir con las espectativas del comprador.<br/>Conoce más sobre estos <a href="http://www.chilexpress.cl/tiempos-de-entrega-envios-paquetes-documentos" target="_blank">tipos de envíos, acá</a>.', 'whq-wcchp' ),
						'options'     => $this->get_shipments_types(),
						'default'     => array( 3, 4, 5 ),
					),
					'locations_cache' => array(
						'title'       => __( 'Caché de ubicaciones', 'whq-wcchp' ),
						'type'        => 'number',
						'description' => __( '(En días) Chilexpress entrega un listado de ubicaciones (Regiones y Ciudades) dinámico.<br/>Para evitar saturar la API de Chilexpress, aquellos listados son guardados localmente en WordPress (Transients). Ingresa el número de días a mantener en el caché el listado de regiones y cuidades.<br/>Mínimo una semana, máximo dos meses.', 'whq-wcchp' ),
						'default'     => 30,
					),
					'extra_wrapper' => array(
						'title'       => __( 'CM extra para caja/embalaje', 'whq-wcchp' ),
						'type'        => 'number',
						'description' => __( '(En centímetros) El plugin permite que añadas X centímetros extra al paquete que enviarás a Chilexpress, esto, para permitirte contabilizar el posible uso de una caja (y el espacio extra que necesitarás para evitar que lo que envias se dañe). Si ya usas ese estimado extra al momento de ingresar el tamaño de los productos a tu tienda, y no deseas utilizar este extra, simplemente deja el valor en cero.', 'whq-wcchp' ),
						'default'     => 0,
					),
					'soap_login' => array(
						'title'       => __( 'Chilexpress API Username', 'whq-wcchp' ),
						'type'        => 'text',
						'description' => __( '(Opcional) Usuario a utilizar en las llamadas a la API de Chilexpress. Dejar en blanco para utilizar datos de conexión por defecto (públicos) que Chilexpress provee.', 'whq-wcchp' ),
						'default'     => __( '', 'whq-wcchp' ),
					),
					'soap_password' => array(
						'title'       => __( 'Chilexpress API Password', 'whq-wcchp' ),
						'type'        => 'password',
						'description' => __( '(Opcional) Contraseña a utilizar en las llamadas a la API de Chilexpress. Dejar en blanco para utilizar datos de conexión por defecto (públicos) que Chilexpress provee.', 'whq-wcchp' ),
						'default'     => __( '', 'whq-wcchp' ),
					),
				);
			}

			public static function get_chilexpress_option( $option_name = '' ) {
				$options = get_option( 'woocommerce_chilexpress_settings' );

				//https://github.com/whooohq/whq-woocommerce-chilexpress-shipping/issues/25
				if ( false === $options ) {
					return false;
				}

				if ( false === array_key_exists( $option_name, $options ) ) {
					return false;
				}

				return $options["$option_name"];
			}

			private function get_shipments_types() {
				//ULTRA RÁPIDO:1
				//OVERNIGHT:2
				//DÍA HÁBIL SIGUIENTE:3
				//DÍA HÁBIL SUBSIGUIENTE:4
				//TERCER DÍA:5

				$shipments_types = [
					'1' => 'Ultra rápido',
					'2' => 'Overnight',
					'3' => 'Día hábil siguiente',
					'4' => 'Día hábil subsiguiente',
					'5' => 'Tercer día',
				];

				return $shipments_types;
			}

			private function get_cities() {
				global $whq_wcchp_default;

				$url    = $whq_wcchp_default['plugin_url'] . 'wsdl/WSDL_GeoReferencia_QA.wsdl';
				$ns     = $whq_wcchp_default['chilexpress_url'] . '/CorpGR/';
				$route  = 'ConsultarCoberturas';
				$method = 'reqObtenerCobertura';

				$codregion        = 99; //Bring it on!
				$codtipocobertura = 1; //Admission
				$parameters       = [ 'CodRegion'        => $codregion,
									  'CodTipoCobertura' => $codtipocobertura ];

				$cities = whq_wcchp_call_soap($ns, $url, $route, $method, $parameters);
				//$cities = false; //Simulate API down

				if( false === $cities ) {
					//Retrieve the hard-coded ones
					$cities = new WC_WHQ_Cities_CL();
					$cities_array = $cities->admission;
				} else {
					$cities = $cities->respObtenerCobertura->Coberturas;

					whq_wcchp_array_move( $cities, 2, 86 );

					if( is_array( $cities ) ) {
						$cities_array = array();
						foreach ($cities as $city) {
							$cities_array["$city->CodComuna"] = $city->GlsComuna;
						}
					} else {
						$cities_array = array( $cities );
					}
				}

				return $cities_array;
			}

			public function is_available( $package ) {
				foreach ( $package['contents'] as $item_id => $values ) {
					$_product = $values['data'];
					$weight   = (int) absint( $_product->get_weight() );

					return true;
				}

				return true;
			}

			/**
			 * calculate_shipping function.
			 *
			 * @access public
			 * @param mixed $package
			 * @return void
			 */
			public function calculate_shipping( $package = array() ) {
				$weight                   = 0;
				$length                   = 0;
				$width                    = 0;
				$height                   = 0;
				$product_package          = array();
				$product_package[0]       = array( 0, 0, 0, 0 );
				$product_package_number   = 1;
				$product_package_quantity = count( $package['contents'] );

				//Generates a package for each product in the cart.
				foreach ( $package['contents'] as $item_id => $values ) {
					$_product = $values['data'];

					//Calculates the final package weight.
					$weight   = round( $weight + $_product->get_weight() * $values['quantity'],3 );

					//Generates the package for the current product.
					$length = round( $_product->get_length(), 1 );
					$width  = round( $_product->get_width(), 1 );
					$height = round( $_product->get_height(), 1 );

					$product_package[$product_package_number] = array(0, $length, $width, $height);

					//Orders the product package dimensions in ascending order.
					sort( $product_package[$product_package_number] );

					//Multiply the smallest product package dimension by the quantity to obtain the final product package.
					$product_package[$product_package_number][1] = round( $product_package[$product_package_number][1] * $values['quantity'], 1 );

					//Reorders the product package dimensions in ascendind order.
					sort( $product_package[$product_package_number] );

					//Calculates the volume of each product package.
					$product_package[$product_package_number][0] = $product_package[$product_package_number][1] * $product_package[$product_package_number][2] * $product_package[$product_package_number][3];

					write_log( "PrdPkgInit({$product_package_number}): Vl={$product_package[$product_package_number][0]} Al={$product_package[$product_package_number][1]} An={$product_package[$product_package_number][2]} La={$product_package[$product_package_number][3]}" );

					$product_package_number++;
				}

				//Orders the product packages by volume descending.
				$auxiliary_array = array( 0, 0, 0, 0 );

				for ( $i=1; $i < $product_package_quantity; $i++ ) {
					for ( $product_package_number=1; $product_package_number < $product_package_quantity; $product_package_number++ ) {
						if ( $product_package[$product_package_number][0] < $product_package[$product_package_number+1][0] ) {
							$auxiliary_array = $product_package[$product_package_number];

							$product_package[$product_package_number] = $product_package[$product_package_number+1];

							$product_package[$product_package_number+1] = $auxiliary_array;
						}
					}
				}

				//To save an extra loop, we check WP_DEBUG first
				if ( true === WP_DEBUG ) {
					for ( $i=1; $i <= $product_package_quantity; $i++ ) {
						write_log( "PrdPkgVol({$i}): Vl={$product_package[$i][0]} Al={$product_package[$i][1]} An={$product_package[$i][2]} La={$product_package[$i][3]}" );
					}
				}

				//If the product packages are more than 3 then makes a new package for every two product packages to improve the final package.
				if ( $product_package_quantity > 3 ) {
					//if the product packages are not even then generates a new empty package.
					if ( ($product_package_quantity % 2) == 1 ) {
						$product_package_quantity++;
						$product_package[$product_package_quantity] = array(0, 0, 0, 0);
					}

					//Joins every two product packages in a new single product package.
					$product_package_number = 1;

					for ( $i=1; $i < $product_package_quantity; $i+=2 ) {
						$product_package[$product_package_number][1] = $product_package[$i][1] + $product_package[$i+1][1];

						if ( $product_package[$i][2] > $product_package[$i+1][2] ) {
							$product_package[$product_package_number][2] = $product_package[$i][2];
						} else {
							$product_package[$product_package_number][2] = $product_package[$i+1][2];
						}

						if ( $product_package[$i][3] > $product_package[$i+1][3] ) {
							$product_package[$product_package_number][3] = $product_package[$i][3];
						} else {
							$product_package[$product_package_number][3] = $product_package[$i+1][3];
						}

						$product_package[$product_package_number][0] = 0;

						//Reorders the new product package dimensions in ascendind order.
						sort( $product_package[$product_package_number] );

						//Calculates the volume for the new product package.
						$product_package[$product_package_number][0] = $product_package[$product_package_number][1] * $product_package[$product_package_number][2] * $product_package[$product_package_number][3];

						$product_package_number++;

					}

					$product_package_quantity = $product_package_quantity / 2;

					//To save an extra loop, we check WP_DEBUG first
					if ( true === WP_DEBUG ) {
						for ($i=1; $i <= $product_package_quantity; $i++) {
							write_log( "PrdPkgRed({$i}): Vl={$product_package[$i][0]} Al={$product_package[$i][1]} An={$product_package[$i][2]} La={$product_package[$i][3]}" );
						}
					}
				}

				//Generates the final package.
				for ( $product_package_number=1; $product_package_number <= $product_package_quantity; $product_package_number++ ) {
					$product_package[0][1] = $product_package[0][1] + $product_package[$product_package_number][1];

					if ( $product_package[$product_package_number][2] > $product_package[0][2] ) {
						$product_package[0][2] = $product_package[$product_package_number][2];
					}

					if ( $product_package[$product_package_number][3] > $product_package[0][3] ) {
						$product_package[0][3] = $product_package[$product_package_number][3];
					}

					//For each product package included reorders the resulting package by the smallest dimension.
					sort( $product_package[0] );

					write_log( "FinPkgPrdPkg({$product_package_number}): Al={$product_package[0][1]} An={$product_package[0][2]} La={$product_package[0][3]}" );
				}

				/*
				Reorders the final package by the largest dimension, adds X cm on each dimension (for the wrapping/box),
				Rounds up each value and trasfers the values to the final variables.
				*/
				sort( $product_package[0] );

				$extra_wrapper = (int) absint( $this->extra_wrapper );

				if( empty( $extra_wrapper ) || false === $extra_wrapper || $extra_wrapper < 0) {
					$extra_wrapper = 0; //No valid value returned?
				}

				$length = ceil( $product_package[0][3] + $extra_wrapper );
				$width  = ceil( $product_package[0][2] + $extra_wrapper );
				$height = ceil( $product_package[0][1] + $extra_wrapper );
				$product_package[0][0] = $length * $width * $height;

				write_log( "FinalPackage: Kg={$weight} Vl={$product_package[0][0]} La={$length} An={$width} Al={$height}" );

				if ( isset( $_POST['s_city'] ) && !is_null( $_POST['s_city'] ) ) {
					$city = $_POST['s_city'];
				} else {
					//And what about WC()->customer->get_shipping_city() ?
					$city = $package['destination']['city'];
				}

				if( !is_null( $city ) ) {
					//Transform city name to city code
					$cities = $this->get_cities();
					if( is_array( $cities) ) {
						foreach ( $cities as $CodComuna => $GlsComuna ) {
							if ( $city == $GlsComuna ) {
								$city = $CodComuna;
								break;
							}
						}
					}

					$chp_cost   = whq_wcchp_get_tarification($city, $this->shipping_origin, $weight, $length, $width, $height);

					if( false === $chp_cost ) {
						$chp_estimated = 0;
					} else {
						$chp_estimated = $chp_cost->respValorizarCourier->Servicios;
					}

					$service_value = 0;

					if( is_array( $chp_estimated ) ) {
						//ULTRA RÁPIDO:1
						//OVERNIGHT:2
						//DÍA HÁBIL SIGUIENTE:3
						//DÍA HÁBIL SUBSIGUIENTE:4
						//TERCER DÍA:5

						$supported_shipments_types = $this->get_chilexpress_option('shipments_types');

						if( false === $supported_shipments_types ) {
							//We need some default values in case the admin hasn't configured this yet
							$supported_shipments_types = array( 2, 3, 4 );
						}

						write_log( $supported_shipments_types );

						foreach ( $chp_estimated as $key => $value ) {
							write_log( 'Servicio: ' . '[' . $value->CodServicio . ']' . $value->GlsServicio . ', valor ' . $value->ValorServicio );

							//We don't wan't to support other kind of shippments for now
							if ( $value->CodServicio >= 6 ) {
								continue;
							}

							//Not supported by this store?
							if ( ! in_array( $value->CodServicio, $supported_shipments_types) ) {
								write_log( '[' . $value->CodServicio . ']' . $value->GlsServicio . ' Not supported by the Store!');
								continue;
							} else {
								write_log( '[' . $value->CodServicio . ']' . $value->GlsServicio . ' is supported');
							}

							$service_id    = $this->id . ':' . $value->CodServicio;
							$service_label = $this->title . ' (' . $value->GlsServicio . ')';
							$service_value = $value->ValorServicio;

							if( false === $service_value || empty( $service_value ) ) {
								$service_id    = $this->id . '_0';
								$service_value = 0;
							}

							$this->add_rate( array(
								'id'    => $service_id,
								'label' => $service_label,
								'cost'  => $service_value
							));
						}
					} else {
						if( false === $chp_cost ) {
							$service_id    = $this->id . ':0';
							$service_label = $this->title . ' (No Disponible)';
							$service_value = 0;
						} else {
							$service_id    = $this->id . ':' . $chp_cost->respValorizarCourier->Servicios->CodServicio;
							$service_label = $this->title . ' (' . $chp_cost->respValorizarCourier->Servicios->GlsServicio . ')';
							$service_value = $chp_cost->respValorizarCourier->Servicios->ValorServicio;
						}

						$this->add_rate( array(
							'id'    => $service_id,
							'label' => $service_label,
							'cost'  => $service_value
						));
					}
				}
			}

			static function create_states( $states ) {
				$cities       = [];
				$states['CL'] = array();

				foreach ($cities as $key => $city) {
					$code                = $city['code'];
					$states['CL'][$code] = $city['name'];
				}

				return $states;
			}

			static function add_cart_fee( WC_Cart $cart ) {
				WC()->cart->calculate_shipping();
			}

			/**
			 * Validate the cache duration
			 */
			public function validate_locations_cache_field( $key, $value ) {
				if ( isset( $value ) && $value < 7 ) {
					WC_Admin_Settings::add_error( esc_html__( 'El caché mínimo para las localidades y regiones es de una semana (7 días).', 'whq-wcchp' ) );

					$value = 7;
				}

				if ( isset( $value ) && $value > 60 ) {
					WC_Admin_Settings::add_error( esc_html__( 'El caché máximo para las localidades y regiones es de dos meses (60 días).', 'whq-wcchp' ) );

					$value = 60;
				}

				return $value;
			}

			/**
			 * Validate the extra package wrapper
			 */
			public function validate_extra_wrapper_field( $key, $value ) {
				if ( isset( $value ) && $value < 0 ) {
					WC_Admin_Settings::add_error( esc_html__( 'El valor mínimo es 0 centímetros.', 'whq-wcchp' ) );

					$value = 0;
				}

				if ( isset( $value ) && $value > 30 ) {
					WC_Admin_Settings::add_error( esc_html__( '¿Estás seguro que necesitas 30 o más centímetros extra para la caja/embalaje?.', 'whq-wcchp' ) ); //Will leave the value in there, just warn the user
				}

				return $value;
			}
		}
	}
}
