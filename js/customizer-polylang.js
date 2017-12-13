/**
 *
 */
/* global wp, jQuery */
/* exported PluginCustomizer */
var PSPolyLang = (function( api, $ ) {
	'use strict';

	var component = {
		data: {
			url: null,
			languages: null,
			current_language: null,
		}
	};

	/**
	 * Initialize functionality.
	 *
	 * @param {object} args Args.
	 * @param {string} args.url  Preview URL.
	 * @returns {void}
	 */
	component.init = function init( pll ) {
		_.extend(component.data, pll );
		if (!pll || !pll.url || !pll.languages || !pll.current_language ) {
			throw new Error( 'Missing args' );
		}

		api.bind( 'ready', function(){
			api.previewer.previewUrl.set( pll.url );

			var languages = pll.languages;
			var current_language = pll.current_language;
			var current_language_name = '';

			var html = '<span style="position:relative;left:38px">Language: </span>';
			html += '<select id="pll-language-select" style="position:relative; left: 35px; top: 1px; padding: 4px 1px;">';
			for (var i = 0; i < languages.length; i++) {
				var language = languages[i];
				var selected = (language.slug === current_language) ? 'selected=""' : '';
				current_language_name = (language.slug === current_language) ? language.name.substr(0, 3) : 'Eng';
				html += '<option ' + selected + ' value="' + language.slug + '">' + language.name.substr(0, 3) + '</option>';
			}
			html += '</select>';
			$(html).prependTo('#customize-header-actions');


			$('body').on('change', '#pll-language-select', function () {
				var language = $(this).val();
				var old_url = window.location.href;
				window.location.href = updateQueryStringParameter(window.location.href, 'lang', language);
			});
		});

		function updateQueryStringParameter(uri, key, value) {
			var re = new RegExp("([?&])" + key + "=.*?(&|$)", "i");
			var separator = uri.indexOf('?') !== -1 ? "&" : "?";
			if (uri.match(re)) {
				return uri.replace(re, '$1' + key + "=" + value + '$2');
			} else {
				return uri + separator + key + "=" + value;
			}
		}
	};

	return component;
} ( wp.customize, jQuery ) );