/**
 * Default settings for jQuery UI Autocomplete for use with non-hierarchical taxonomies.
 */
jQuery(document).ready(function( $ ) {
	// Handle categories.
	$('.multisite-hierarchical-taxonomy-div').each( function(){
		var this_id = $(this).attr('id'), multisiteTaxonomyAddBefore, multisiteTaxonomyAddAfter, taxonomyParts, taxonomy, settingName;

		taxonomyParts = this_id.split('-');
		taxonomyParts.shift();
		taxonomy = taxonomyParts.join('-');
        settingName = taxonomy + '_tab';

		// TODO: move to jQuery 1.3+, support for multiple hierarchical taxonomies, see wp-lists.js
		$('a', '#' + taxonomy + '-tabs').click( function( e ) {
			e.preventDefault();
			var t = $(this).attr('href');
			$(this).parent().addClass('tabs').siblings('li').removeClass('tabs');
			$('#' + taxonomy + '-tabs').siblings('.tabs-panel').hide();
			$(t).show();
			if ( '#' + taxonomy + '-all' == t ) {
				deleteUserSetting( settingName );
			} else {
				setUserSetting( settingName, 'pop' );
			}
		});

		if ( getUserSetting( settingName ) )
			$('a[href="#' + taxonomy + '-pop"]', '#' + taxonomy + '-tabs').click();

		// Add category button controls.
		$('#new_multisite_' + taxonomy).one( 'focus', function() {
			$( this ).val( '' ).removeClass( 'form-input-tip' );
		});

		// On [enter] submit the taxonomy.
		$('#new_multisite_' + taxonomy).keypress( function(event){
			if( 13 === event.keyCode ) {
				event.preventDefault();
				$('#' + taxonomy + '-add-submit').click();
			}
		});

		// After submitting a new taxonomy, re-focus the input field.
		$('#' + taxonomy + '-add-submit').click( function() {
			$('#new' + taxonomy).focus();
		});

		/**
		 * Before adding a new taxonomy, disable submit button.
		 *
		 * @param {Object} s Taxonomy object which will be added.
		 *
		 * @returns {Object}
		 */
		multisiteTaxonomyAddBefore = function( s ) {
			if ( !$('#new_multisite_' + taxonomy).val() ) {
				return false;
			}

			s.data += '&' + $( ':checked', '#'+taxonomy+'checklist' ).serialize();
            $( '#' + taxonomy + '-add-submit' ).prop( 'disabled', true );
            console.log(s);
			return s;
		};

		/**
		 * Re-enable submit button after a taxonomy has been added.
		 *
		 * Re-enable submit button.
		 * If the taxonomy has a parent place the taxonomy underneath the parent.
		 *
		 * @param {Object} r Response.
		 * @param {Object} s Taxonomy data.
		 *
		 * @returns void
		 */
		multisiteTaxonomyAddAfter = function( r, s ) {
			var sup, drop = $('#new_multisite_' + taxonomy + '_parent');

			$( '#' + taxonomy + '-add-submit' ).prop( 'disabled', false );
			if ( 'undefined' != s.parsed.responses[0] && (sup = s.parsed.responses[0].supplemental.new_multisite_term_parent) ) {
				drop.before(sup);
				drop.remove();
			}
		};


		$('#' + taxonomy + 'checklist').wpList({
            alt: '',
            what: 'multisite-hierarchical-term-' + taxonomy,
			response: taxonomy + '-ajax-response',
			addBefore: multisiteTaxonomyAddBefore,
			addAfter: multisiteTaxonomyAddAfter
		});

		// Add new taxonomy button toggles input form visibility.
		$('#' + taxonomy + '-add-toggle').click( function( e ) {
			e.preventDefault();
			$('#' + taxonomy + '-adder').toggleClass( 'wp-hidden-children' );
			$('a[href="#' + taxonomy + '-all"]', '#' + taxonomy + '-tabs').click();
			$('#new'+taxonomy).focus();
		});

		// Sync checked items between "All {taxonomy}" and "Most used" lists.
		$('#' + taxonomy + 'checklist, #' + taxonomy + 'checklist-pop').on( 'click', 'li.popular-category > label input[type="checkbox"]', function() {
			var t = $(this), c = t.is(':checked'), id = t.val();
			if ( id && t.parents('#taxonomy-'+taxonomy).length )
				$('#in-' + taxonomy + '-' + id + ', #in-popular-' + taxonomy + '-' + id).prop( 'checked', c );
		});

	}); // end cats

	// Make nested terms collapsible. Collapsed by default, but any branch that
	// contains a checked/selected term is expanded so the selection stays visible.
	$( '.hierarchical-term-checklist' ).each( function() {
		var $list = $( this );

		function setExpanded( $li, expanded ) {
			$li.toggleClass( 'mtax-expanded', expanded );
			$li.children( '.mtax-term-toggle' ).attr( 'aria-expanded', expanded ? 'true' : 'false' );
			$li.children( 'ul.children' ).toggleClass( 'mtax-collapsed', ! expanded );
		}

		// Tag every parent term and give it a toggle, then collapse it.
		$list.find( 'li' ).filter( function() {
			return $( this ).children( 'ul.children' ).length > 0;
		} ).each( function() {
			var $li = $( this );
			$li.addClass( 'has-children' );
			$li.prepend( '<button type="button" class="mtax-term-toggle" aria-expanded="false"><span class="mtax-toggle-indicator" aria-hidden="true"></span></button>' );
			setExpanded( $li, false );
		} );

		// Toggle on click.
		$list.on( 'click', '.mtax-term-toggle', function( e ) {
			e.preventDefault();
			var $li = $( this ).closest( 'li' );
			setExpanded( $li, ! $li.hasClass( 'mtax-expanded' ) );
		} );

		// Reveal branches holding a checked (checkbox) or selected (list_only) term.
		$list.find( 'input[type="checkbox"]:checked, .category.selected' ).each( function() {
			$( this ).parentsUntil( $list, 'li.has-children' ).each( function() {
				setExpanded( $( this ), true );
			} );
		} );
	} );

}); // end Jquery
