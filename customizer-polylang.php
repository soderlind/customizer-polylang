<?php
/**
 * If Polylang is active:
 * - save and retrieve customizer setting per language
 * - on front-page, set options and theme mod for the selected lamguage
 *
 * Inspired by https://github.com/fastlinemedia/customizer-export-import
 */
namespace Soderlind\Customizer\Polylang; // replace with your namespace

if ( ! \function_exists( 'pll_current_language' ) ) {
	return; // Polylang isn't activated, bail out
}

\add_action( 'customize_save_after'              , __NAMESPACE__ . '\CustomizerPolylang::save_settings', 1000 );
\add_action( 'plugins_loaded'                    , __NAMESPACE__ . '\CustomizerPolylang::load_settings', 9 ); // Must happen before 10 when _wp_customize_include() fires.
\add_action( 'after_setup_theme'                 , __NAMESPACE__ . '\CustomizerPolylang::load_settings' );
\add_action( 'customize_controls_enqueue_scripts', __NAMESPACE__ . '\CustomizerPolylang::add_lang_to_customizer_previewer', 9 );
\add_action( 'wp_before_admin_bar_render'        , __NAMESPACE__ . '\CustomizerPolylang::on_wp_before_admin_bar_render', 100 );
\add_action( 'admin_menu'                        , __NAMESPACE__ . '\CustomizerPolylang::on_admin_menu', 100 );


interface CustimizerPolylangInterface {
	public static function save_settings( $wp_customize);
	public static function load_settings( $wp_customize = null);
}

class CustomizerPolylang implements CustimizerPolylangInterface {

	public static function save_settings( $wp_customize ) {
		if ( ! function_exists( 'pll_current_language' ) ) {
			return;
		}

		$language = pll_current_language();

		$theme    = get_stylesheet();
		$template = get_template();
		$charset  = get_option( 'blog_charset' );
		$mods     = get_theme_mods();
		$data     = [
			'template' => $template,
			'mods'     => $mods ? $mods : [],
			'options'  => [],
		];
		// Get options from the Customizer API.
		$settings = $wp_customize->settings();
		foreach ( $settings as $key => $setting ) {
			if ( 'option' == $setting->type ) {
				switch ( $key ) {
					// icnore these
					case stristr( $key, 'widget_' ):
					case stristr( $key, 'sidebars_' ):
					case stristr( $key, 'nav_menus_' ):
						continue;
						break;

					default:
						$data['options'][ $key ] = $setting->value();
						break;
				}
			}
		}

		foreach ( $data['options'] as $option_key ) {
			$option_value = get_option( $option_key );
			if ( $option_value ) {
				$data['options'][ $option_key ] = $option_value;
			}
		}
		if ( function_exists( 'wp_get_custom_css_post' ) ) {
			$data['wp_css'] = wp_get_custom_css();
		}

		$option_prefix = \str_replace( '-', '_', $template );
		\update_option( $option_prefix . '_customizer_polylang_settings_' . $language, $data );
	}

	public static function load_settings( $wp_customize = null ) {
		global $cei_error;

		if ( ! function_exists( 'pll_current_language' ) ) {
			return;
		}

		$language      = pll_current_language();
		$template      = get_template();
		$option_prefix = \str_replace( '-', '_', $template );
		$data          = get_option( $option_prefix . '_customizer_polylang_settings_' . $language, false );

		if ( $data ) {
			// Data checks.
			if ( 'array' != gettype( $data ) ) {
				return;
			}
			if ( ! isset( $data['template'] ) || ! isset( $data['mods'] ) ) {
				return;
			}
			if ( $data['template'] != $template ) {
				return;
			}

			// Import custom options.
			if ( isset( $data['options'] ) ) {

				foreach ( $data['options'] as $option_key => $option_value ) {
					if ( \class_exists( 'CustomizerPolylangOption' ) ) {
						$option = new CustomizerPolylangOption(
							$wp_customize, $option_key, [
								'default'    => '',
								'type'       => 'option',
								'capability' => 'edit_theme_options',
							]
						);
						$option->import( $option_value );
					} else {
						update_option( $option_key, $option_value );
					}
				}
			}
			// If wp_css is set then import it.
			if ( function_exists( 'wp_update_custom_css_post' ) && isset( $data['wp_css'] ) && '' !== $data['wp_css'] ) {
				wp_update_custom_css_post( $data['wp_css'] );
			}
			foreach ( $data['mods'] as $key => $val ) {
				set_theme_mod( $key, $val );
			}
		}
	}

	/**
	 * If Polylang activated, set the preview url and add select language control
	 *
	 * @author soderlind
	 * @version 1.0.0
	 * @link https://gist.github.com/soderlind/1908634f5eb0c1f69428666dd2a291d0
	 */
	public static function add_lang_to_customizer_previewer() {
		if ( function_exists( 'pll_current_language' ) ) {
			$handle    = 'dss-add-lang-to-template';
			$js_path_url = trailingslashit( apply_filters( 'scp_js_path_url', get_stylesheet_directory_uri() . '/js/' ) );
			$src       = $js_path_url . '/customizer-polylang.js';
			$deps      = [ 'customize-controls' ];
			$version   = rand();
			$in_footer = 1;
			wp_enqueue_script( $handle, $src, $deps, $version, $in_footer );
			$language = ( empty( $_REQUEST['lang'] ) ) ? pll_current_language() : $_REQUEST['lang'];

			if ( empty( $language ) ) {
				$language = pll_default_language();
			}
			$url = add_query_arg( 'lang', $language, pll_home_url( $language ) );

			wp_add_inline_script(
				$handle,
				sprintf(
					'PSPolyLang.init( %s );', wp_json_encode(
						[
							'url'              => $url,
							'languages'        => get_option( '_transient_pll_languages_list' ),
							'current_language' => $language,
						]
					)
				), 'after'
			);
		}
	}

	/**
	 * Append lang="contrycode" to the customizer url in the adminbar
	 *
	 * @return void
	 */
	public static function on_wp_before_admin_bar_render() {
		global $wp_admin_bar;
		$customize_node = $wp_admin_bar->get_node( 'customize' );
		if ( ! empty( $customize_node ) ) {
			$customize_node->href = add_query_arg( 'lang', pll_current_language(), $customize_node->href );
			$wp_admin_bar->add_node( $customize_node );
		}
	}

	/**
	 * Append lang="contrycode" to the customizer url in the Admin->Apperance->Customize menu
	 *
	 * @return void
	 */
	public static function on_admin_menu() {
		global $menu, $submenu;
		$parent = 'themes.php';
		if ( ! isset( $submenu[ $parent ] ) ) {
			return;
		}
		foreach ( $submenu[ $parent ] as $k => $d ) {
			if ( 'customize' == $d['1'] ) {
				$submenu[ $parent ][ $k ]['2'] = add_query_arg( 'lang', pll_current_language(), $submenu[ $parent ][ $k ]['2'] );
				break;
			}
		}
	}

}

if ( class_exists( 'WP_Customize_Setting' ) ) {
	/**
	 * A class that extends WP_Customize_Setting so we can access
	 * the protected updated method when importing options.
	 *
	 * @since 0.3
	 */
	final class CustomizerPolylangOption extends \WP_Customize_Setting {


		/**
		 * Import an option value for this setting.
		 *
		 * @since 0.3
		 * @param mixed $value The option value.
		 * @return void
		 */
		public function import( $value ) {
			$this->update( $value );
		}
	}
}
