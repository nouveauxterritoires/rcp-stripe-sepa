/**
 * Bascule d'une adhésion vers le prélèvement SEPA.
 *
 * Le parcours est en deux temps, parce que l'acceptation du mandat a lieu ici :
 * le serveur prépare une intention d'enregistrement, le navigateur la confirme
 * avec l'IBAN, puis le serveur applique le résultat.
 *
 * @package RCP_Stripe_Sepa
 */

/* global Stripe, rcpStripeSepaMigration, jQuery */

( function ( $ ) {
	'use strict';

	var stripe = null;
	var forms = {};

	/**
	 * Instance Stripe, créée à la demande.
	 *
	 * @return {Object} Instance Stripe.
	 */
	function client() {
		if ( ! stripe ) {
			stripe = Stripe( rcpStripeSepaMigration.publishableKey, {
				locale: rcpStripeSepaMigration.locale
			} );
		}

		return stripe;
	}

	/**
	 * Affiche un message dans le formulaire.
	 *
	 * @param {jQuery} $form   Formulaire concerné.
	 * @param {string} message Message à afficher.
	 */
	function showMessage( $form, message ) {
		$form.find( '.rcp-stripe-sepa-errors' ).text( message || '' );
	}

	/**
	 * Monte l'élément IBAN d'un formulaire.
	 *
	 * @param {jQuery} $form Formulaire concerné.
	 * @return {Object} Élément IBAN.
	 */
	function mount( $form ) {
		var membership = $form.data( 'membership' );

		if ( forms[ membership ] ) {
			return forms[ membership ];
		}

		var elements = client().elements();
		var iban = elements.create( 'iban', { supportedCountries: [ 'SEPA' ] } );

		iban.mount( $form.find( '.rcp-stripe-sepa-element' )[ 0 ] );
		iban.on( 'change', function ( event ) {
			showMessage( $form, event.error ? event.error.message : '' );
		} );

		forms[ membership ] = iban;

		return iban;
	}

	/**
	 * Envoie une requête AJAX de migration.
	 *
	 * @param {string} action     Action WordPress.
	 * @param {Object} payload    Données supplémentaires.
	 * @return {Promise} Promesse jQuery.
	 */
	function request( action, payload ) {
		return $.post(
			rcpStripeSepaMigration.ajaxUrl,
			$.extend(
				{ action: action, nonce: rcpStripeSepaMigration.nonce },
				payload
			)
		);
	}

	/**
	 * Déroule la bascule pour un formulaire.
	 *
	 * @param {jQuery} $form Formulaire concerné.
	 */
	function migrate( $form ) {
		var membership = $form.data( 'membership' );
		var holderName = $form.find( '.rcp-stripe-sepa-holder-name' ).val();
		var $submit = $form.find( '.rcp-stripe-sepa-migrate-submit' );

		if ( ! holderName ) {
			showMessage( $form, rcpStripeSepaMigration.strings.missingName );
			return;
		}

		$submit.prop( 'disabled', true );
		showMessage( $form, rcpStripeSepaMigration.strings.working );

		request( 'rcp_stripe_sepa_start_migration', { membership_id: membership } )
			.then( function ( response ) {
				if ( ! response.success ) {
					return $.Deferred().reject( response.data && response.data.message );
				}

				return client().confirmSepaDebitSetup(
					response.data.client_secret,
					{
						payment_method: {
							sepa_debit: forms[ membership ],
							billing_details: {
								name: holderName,
								email: rcpStripeSepaMigration.email
							}
						}
					}
				).then( function ( result ) {
					if ( result.error ) {
						return $.Deferred().reject( result.error.message );
					}

					return request( 'rcp_stripe_sepa_complete_migration', {
						membership_id: membership,
						setup_intent_id: result.setupIntent.id
					} );
				} );
			} )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					return $.Deferred().reject( response && response.data && response.data.message );
				}

				showMessage( $form, rcpStripeSepaMigration.strings.success );
				$form.find( 'input, button' ).prop( 'disabled', true );
			} )
			.fail( function ( message ) {
				showMessage(
					$form,
					typeof message === 'string' && message
						? message
						: rcpStripeSepaMigration.strings.failure
				);
				$submit.prop( 'disabled', false );
			} );
	}

	$( document ).ready( function () {
		$( document ).on( 'click', '.rcp-stripe-sepa-migrate-toggle', function () {
			var $toggle = $( this );
			var $form = $( '#rcp-stripe-sepa-migration-' + $toggle.data( 'membership' ) );
			var expanded = 'true' === $toggle.attr( 'aria-expanded' );

			$toggle.attr( 'aria-expanded', expanded ? 'false' : 'true' );
			$form.prop( 'hidden', expanded );

			if ( ! expanded ) {
				mount( $form );
			}
		} );

		$( document ).on( 'click', '.rcp-stripe-sepa-migrate-submit', function () {
			migrate( $( this ).closest( '.rcp-stripe-sepa-migration' ) );
		} );
	} );
}( jQuery ) );
