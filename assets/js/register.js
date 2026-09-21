/**
 * Collecte et confirmation du mandat SEPA.
 *
 * L'IBAN est saisi dans un Stripe Element — une iframe servie par Stripe — et
 * n'atteint jamais le serveur WordPress. La confirmation recueille par ailleurs
 * l'acceptation du mandat par le débiteur, qui vaut signature électronique :
 * elle doit donc avoir lieu ici, dans le navigateur, et non côté serveur.
 *
 * @package RCP_Stripe_Sepa
 */

/* global Stripe, rcpStripeSepa, jQuery */

( function ( $ ) {
	'use strict';

	var state = {
		stripe: null,
		elements: null,
		iban: null,
		mounted: false
	};

	/**
	 * Affiche un message d'erreur sous le formulaire.
	 *
	 * @param {string} message Message à afficher.
	 */
	function showError( message ) {
		var container = document.getElementById( 'rcp-stripe-sepa-errors' );

		if ( container ) {
			container.textContent = message || '';
		}
	}

	/**
	 * Rend au formulaire la main après un échec.
	 */
	function releaseForm() {
		$( '#rcp_registration_form' ).find( '#rcp_submit' ).prop( 'disabled', false );
		$( '#rcp_ajax_loading' ).hide();
		$( '#rcp_registration_form' ).removeClass( 'rcp_processing' );
	}

	/**
	 * Monte l'élément IBAN, une fois la passerelle sélectionnée.
	 */
	function mountElements() {
		var container = document.getElementById( 'rcp-stripe-sepa-iban-element' );

		if ( ! container || state.mounted ) {
			return;
		}

		if ( ! state.stripe ) {
			state.stripe = Stripe( rcpStripeSepa.publishableKey, { locale: rcpStripeSepa.locale } );
			state.elements = state.stripe.elements();
		}

		state.iban = state.elements.create( 'iban', {
			supportedCountries: [ 'SEPA' ],
			placeholderCountry: ( rcpStripeSepa.locale || 'fr' ).toUpperCase()
		} );

		state.iban.mount( container );
		state.iban.on( 'change', function ( event ) {
			showError( event.error ? event.error.message : '' );
		} );

		state.mounted = true;
	}

	/**
	 * Démonte l'élément lorsqu'une autre passerelle est choisie.
	 */
	function unmountElements() {
		if ( state.mounted && state.iban ) {
			state.iban.unmount();
			state.mounted = false;
		}
	}

	/**
	 * Confirme l'intention renvoyée par le serveur.
	 *
	 * @param {Object} response Réponse AJAX de RCP.
	 */
	function confirmMandate( response ) {
		var data = response && response.gateway ? response.gateway.data : null;
		var holderName = $( '#rcp-stripe-sepa-holder-name' ).val();
		var email = $( '#rcp_user_email' ).val() || '';

		if ( ! data || ! data.stripe_client_secret ) {
			showError( rcpStripeSepa.strings.genericError );
			releaseForm();
			return;
		}

		if ( ! holderName ) {
			showError( rcpStripeSepa.strings.missingName );
			releaseForm();
			return;
		}

		var isSetup = 'setup_intent' === data.stripe_intent_type;
		var confirm = isSetup
			? state.stripe.confirmSepaDebitSetup
			: state.stripe.confirmSepaDebitPayment;

		confirm.call(
			state.stripe,
			data.stripe_client_secret,
			{
				payment_method: {
					sepa_debit: state.iban,
					billing_details: {
						name: holderName,
						email: email
					}
				}
			}
		).then( function ( result ) {
			if ( result.error ) {
				showError( result.error.message || rcpStripeSepa.strings.genericError );
				releaseForm();
				return;
			}

			// Le mandat est accepté : le formulaire peut être soumis au serveur,
			// qui créera l'abonnement et attendra le webhook d'encaissement.
			$( '#rcp_registration_form' ).off( 'submit' ).trigger( 'submit' );
		} ).catch( function () {
			showError( rcpStripeSepa.strings.genericError );
			releaseForm();
		} );
	}

	$( document ).ready( function () {
		$( 'body' ).on( 'rcp_gateway_loaded', function ( event, gateway ) {
			if ( rcpStripeSepa.gateway === gateway ) {
				mountElements();
			} else {
				unmountElements();
			}
		} );

		$( 'body' ).on( 'rcp_registration_form_processed', function ( event, response ) {
			if ( response && response.gateway && rcpStripeSepa.gateway === response.gateway.slug ) {
				confirmMandate( response );
			}
		} );

		mountElements();
	} );
}( jQuery ) );
