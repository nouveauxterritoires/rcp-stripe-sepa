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

		if ( ! container ) {
			return;
		}

		// Le conteneur est reconstruit à chaque changement de passerelle :
		// un élément monté sur un nœud détaché ne recevrait plus rien.
		if ( state.mounted && container.contains( state.iban && state.iban._parent ) ) {
			return;
		}

		if ( state.mounted ) {
			unmountElements();
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
	 * Confirme le mandat, puis rend la main à Restrict Content Pro.
	 *
	 * La signature suit celle de RCP : l'événement transmet le formulaire
	 * **puis** la réponse. C'est aussi RCP qui conclut l'inscription, par
	 * `rcp_submit_registration_form()` — re-déclencher un `submit` sur le
	 * formulaire court-circuiterait son traitement.
	 *
	 * @param {Object} event    Événement jQuery.
	 * @param {Object} form     Formulaire d'inscription.
	 * @param {Object} response Réponse AJAX de RCP.
	 */
	function confirmMandate( event, form, response ) {
		if ( ! response || ! response.gateway || rcpStripeSepa.gateway !== response.gateway.slug ) {
			return;
		}

		var data = response.gateway.data;
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

			// Le mandat est accepté : RCP peut finaliser l'inscription, créer
			// l'abonnement et attendre le webhook d'encaissement.
			if ( 'function' === typeof window.rcp_submit_registration_form ) {
				window.rcp_submit_registration_form( form, response );
				return;
			}

			showError( rcpStripeSepa.strings.genericError );
			releaseForm();
		} ).catch( function () {
			showError( rcpStripeSepa.strings.genericError );
			releaseForm();
		} );
	}

	$( document ).ready( function () {
		/*
		 * Le conteneur n'est présent dans le document que lorsque la passerelle
		 * SEPA est sélectionnée : sa présence suffit à décider du montage, sans
		 * avoir à interpréter la forme de l'argument transmis par RCP.
		 */
		$( 'body' ).on( 'rcp_gateway_loaded', function () {
			if ( document.getElementById( 'rcp-stripe-sepa-iban-element' ) ) {
				mountElements();
			} else {
				unmountElements();
			}
		} );

		$( 'body' ).on( 'rcp_registration_form_processed', confirmMandate );

		mountElements();
	} );
}( jQuery ) );
