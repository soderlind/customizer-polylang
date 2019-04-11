<?php
/**
 * If Polylang is active:
 * - save and retrieve customizer setting per language
 * - on front-page, set options and theme mod for the selected language
 * - expect customizer to use setting type = theme_mod (the customizer default), only exceptions are 'blog', 'blogname' and 'site_icon' (see $option_types )
 *
 * Inspired by https://github.com/fastlinemedia/customizer-export-import
 */
namespace Soderlind\Customizer\Polylang; // replace with your namespace

if ( ! \function_exists( 'pll_current_language' ) || ! \function_exists( 'pll_default_language' ) ) {
	return;
}

// Instantiate the class.
$GLOBAL['customizer_polylang'] = Customizer_Polylang::init();

/**
 * Class Customizer_Polylang
 *
 * @package Dekode\DSS\Nettsteder_Mal
 */
class Customizer_Polylang {

	/**
	 * Static factory
	 *
	 * @link https://carlalexander.ca/static-factory-method-pattern-wordpress/, https://carlalexander.ca/designing-class-wordpress-hooks/
	 *
	 * @return void
	 */
	public static function init() {
		$self = new self();

		/**
		 * Force "The language is set from content" (in Language->Settings->URL modifications)
		 */
		$options = get_option( 'polylang' );
		if ( isset( $options['force_lang'] ) && 0 !== $options['force_lang'] ) {
			$options['force_lang'] = 0;
			update_option( 'polylang', $options );
		}
		/**
		 * Disable detect browser language, will return default language instead.
		 */
		add_filter( 'pll_preferred_language', '__return_false' );

		\add_action( 'customize_controls_enqueue_scripts', [ $self, 'add_lang_to_customizer_previewer' ], 9 );
		\add_action( 'wp_before_admin_bar_render', [ $self, 'on_wp_before_admin_bar_render' ], 100 );
		\add_action( 'admin_menu', [ $self, 'on_admin_menu' ], 100 );

		$theme_stylesheet_slug = get_option( 'stylesheet' );
		$option_types          = [ 'blogname', 'blogdescription', 'site_icon' ];

		// Get theme mod options.
		add_filter( 'option_theme_mods_' . $theme_stylesheet_slug, [ $self, 'on_option_theme_mods_get' ], 10, 1 );
		// Update theme mod options.
		add_filter( 'pre_update_option_theme_mods_' . $theme_stylesheet_slug, [ $self, 'on_option_theme_mods_update' ], 10, 2 );

		foreach ( $option_types as $option_type ) {
			add_filter( 'pre_option_' . $option_type, [ $self, 'on_wp_option_get' ], 10, 3 ); // get_option hook.
			add_filter( 'pre_update_option_' . $option_type, [ $self, 'on_wp_option_update' ], 10, 3 ); // update_option hook.
		}

		return $self;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Helper to fetch custom customizer db content.
	 *
	 * @return mixed Customizer array or false.
	 */
	protected function get_custom_customizer_option() {
		$current_language = pll_current_language();
		$theme_slug       = get_option( 'template' );
		$option_prefix    = \str_replace( '-', '_', $theme_slug );
		$option_name      = $option_prefix . '_customizer_polylang_settings_' . $current_language;

		return get_option( $option_name, false );
	}

	/**
	 * Helper to update custom customizer db content.
	 *
	 * @param mixed $data Data to insert.
	 *
	 * @return bool Success.
	 */
	protected function update_custom_customizer_option( $data ) {
		$current_language = pll_current_language();
		$theme_slug       = get_option( 'template' );
		$option_prefix    = \str_replace( '-', '_', $theme_slug );
		$option_name      = $option_prefix . '_customizer_polylang_settings_' . $current_language;

		return update_option( $option_name, $data );
	}

	/**
	 * Helper
	 *
	 * @return bool If the current language is the default language.
	 */
	protected function current_lang_not_default() {
		return pll_current_language() !== pll_default_language();
	}

	/**
	 * Check the custom db field on get_option hook to be able to return custom language value.
	 * If the current language is default, then return from default wp option
	 *
	 * @param bool   $pre_option This is false. If something else is returned wp exits the check in db and uses this value.
	 * @param string $option Option name asked for.
	 * @param mixed  $default Default value, second args when asking for options.
	 *
	 * @return mixed
	 */
	public function on_wp_option_get( $pre_option, $option, $default ) {

		// If not the default language, then skip the custom check and wp will the use default options.
		if ( $this->current_lang_not_default() ) {
			$data = $this->get_custom_customizer_option();

			// Found the custom option. Move on.
			if ( is_array( $data ) && isset( $data['options'] ) && isset( $data['options'][ $option ] ) ) {
				return $data['options'][ $option ];
			}
		}

		return $default;
	}

	/**
	 * Update the custom db field on get_option hook.
	 * If the current language is not default, then return old value to prevent from saving to default wp option.
	 *
	 * @param mixed  $value The new, unserialized option value.
	 * @param mixed  $old_value The old option value.
	 * @param string $option Option name.
	 *
	 * @return mixed
	 */
	public function on_wp_option_update( $value, $old_value, $option ) {
		// Fetch custom option db field.
		$data       = $this->get_custom_customizer_option();
		$theme_slug = get_option( 'template' );
		// If false, the field hasn't been created yet, so it must be created.
		if ( false === $data ) {
			$data = [
				'template' => $theme_slug,
				'mods'     => [],
				'options'  => [],
			];
		}

		// Make sure the options array exists. We are going to use it soon.
		if ( ! isset( $data['options'] ) ) {
			$data['options'] = [];
		}

		$data['options'][ $option ] = $value;

		// Update option value in custom db field. (Not necessary to save for default language since it uses default wp option fields for values when get option).
		$this->update_custom_customizer_option( $data );

		// If the current language is not the default language, prevent saving to option table by passing the old value back. It will then exit after the filter.
		if ( $this->current_lang_not_default() ) {
			return $old_value;
		}

		return $value;
	}

	/**
	 * Check the custom db field on get_option customizer field option name hook to be able to return custom language value.
	 * Parse arguments with default wp customizer values to make sure all are present in the return.
	 *
	 * @param array $value The customizer settings.
	 *
	 * @return array
	 */
	public function on_option_theme_mods_get( $value ) {
		$data = $this->get_custom_customizer_option();

		if ( isset( $data['mods'] ) && is_array( $data['mods'] ) && ! empty( $data['mods'] ) ) {
			$value = wp_parse_args( $data['mods'], $value );
		}

		// Remove members with integer key from mods.
		foreach ( $value as $key => $mod ) {
			if ( is_numeric( $key ) ) {
				unset( $value[ $key ] );
			}
		}

		return $value;
	}

	/**
	 * Update custom customizer option.
	 * If the current language is not default, then return old value to prevent from saving to customizer wp option.
	 *
	 * @param mixed $value The new, unserialized option value.
	 * @param mixed $old_value The old option value.
	 */
	public function on_option_theme_mods_update( $value, $old_value ) {

		$current_data = $this->get_custom_customizer_option();
		$theme_slug   = get_option( 'template' );

		$data = [
			'template' => $theme_slug,
			'mods'     => isset( $current_data['mods'] ) ? $current_data['mods'] : [],
			'options'  => isset( $current_data['options'] ) ? $current_data['options'] : [],
		];

		if ( is_array( $value ) && ! empty( $value ) ) {
			foreach ( $value as $key => $val ) {
				$data['mods'][ $key ] = $val;
			}
		}
		$this->update_custom_customizer_option( $data );

		if ( $this->current_lang_not_default() ) {
			return $old_value;
		}

		return $value;
	}

	/**
	 * If Polylang activated, set the preview url and add select language control
	 *
	 * @author soderlind
	 * @version 1.0.0
	 * @link https://gist.github.com/soderlind/1908634f5eb0c1f69428666dd2a291d0
	 */
	public function add_lang_to_customizer_previewer() {

		$handle    = 'dss-add-lang-to-template';
		$src       = get_stylesheet_directory_uri() . '/js/customizer-polylang.js';
		$deps      = [ 'customize-controls' ];
		$version   = rand();
		$in_footer = 1;
		wp_enqueue_script( $handle, $src, $deps, $version, $in_footer );
		$language = ( empty( $_REQUEST['lang'] ) ) ? pll_current_language() : $_REQUEST['lang'];

		if ( empty( $language ) ) {
			$language = pll_default_language();
		}

		if ( ! empty( $_REQUEST['url'] ) ) {
			$current_url = add_query_arg( 'lang', $language, $_REQUEST['url'] );
		} else {
			$current_url = add_query_arg( 'lang', $language, pll_home_url( $language ) );
		}
		wp_add_inline_script(
			$handle,
			sprintf(
				'PSPolyLang.init( %s );',
				wp_json_encode(
					[
						'url'              => $current_url,
						'languages'        => get_transient( 'pll_languages_list' ),
						'current_language' => $language,
					]
				)
			),
			'after'
		);
	}

	/**
	 * Append lang="contrycode" to the customizer url in the adminbar
	 *
	 * @return void
	 */
	public function on_wp_before_admin_bar_render() {
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
	public function on_admin_menu() {
		global $submenu;
		$parent = 'themes.php';
		if ( ! isset( $submenu[ $parent ] ) ) {
			return;
		}
		foreach ( $submenu[ $parent ] as $k => $d ) {
			if ( 'customize' === $d['1'] ) {
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
		 *
		 * @param mixed $value The option value.
		 *
		 * @return void
		 */
		public function import( $value ) {
			$this->update( $value );
		}
	}
}

/**
 * Polylang register strings.
 */

if ( function_exists( 'pll_register_string' ) ) {

	/**
	 * Register fields for Polylang string translations
	 *
	 * @param string $option_name Option name
	 * @param array  $fields Field names
	 *
	 * @return void
	 */
	function register_polylang_column_strings( $option_name, $fields ) {
		$columns    = get_option( $option_name );
		$theme_name = wp_get_theme()->get( 'Name' );
		if ( ! empty( $columns ) ) :
			for ( $i = 0; $i < $columns; $i ++ ) :
				foreach ( $fields as $field ) {
					pll_register_string( $option_name . '_' . $i . '_' . $field, get_option( $option_name . '_' . $i . '_' . $field ), $theme_name, true );
				}
			endfor;
		endif;
	}

	register_polylang_column_strings( 'options_footer_columns', [ 'title', 'text' ] );
	register_polylang_column_strings( 'options_footer_logos', [ 'image', 'url' ] );
}
