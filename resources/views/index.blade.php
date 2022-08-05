<!--
 Copyright (c) 2018 Five9, Inc. The content presented herein may not, under any
 circumstances, be reproduced in whole or in any part or form without written
 permission from Five9, Inc.
-->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href='https://www.five9.com/favicon.ico' rel='shortcut icon'>

    <title>Five9 SecurePay API Library Sample App</title>

    <!-- jQuery -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>

    <!-- Bootstrap for styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ka7Sk0Gln4gmtz2MlQnikT1wXgYsOg+OMhuP+IlRH9sENBO0LRn5q+8nbTov4+1p" crossorigin="anonymous"></script>

    <!-- ================================= -->
    <!-- Secure Payment specific libraries -->
    <!-- ================================= -->

    <!-- These two Libraries represent the SIMPLE Secure Payment Libaries.
    -->
    <!-- (1) The Five9 SIMPLE JS Library
        Provides methods that leverage the Five9 REST API directly for starting and stoping a secure payment conference call.
    -->
    <script type="text/javascript">
			/**************************************************************************
		 * f9secpay.js
		 *
		 * This javascript library is used in provide Secure Payment IVR integration
		 * from a Salesforce Lightning Web Component.
		 *
		 *
		 **************************************************************************/

		/* eslint-disable no-console */
		// secpay.js

		var secpay = (function () {
			var cavsToPass;
			var eventCallback;
			var cavs;
			var campaigns;
			var activeF9md;
			const SECURE_IVR_CAV = '__FIVE9__.secure_ivr';

			// Method used to initialize the secure payment library
			function initialize(callback) {
				//alert('initialize');
				eventCallback = callback;
			}

			// Method used to initiate the secure payment
			function startSecurePayment(cavsPassed) {
				//alert('Start Secure Pay');
				cavsToPass = cavsPassed;
				getFive9MetaData();
			}

			// Method used to cancel an active secure pay IVR session
			function cancelSecurePayment() {
				cancelConference();
			}

			function log(message) {
				console.info(`Five SecPay Client: ${message}`);
			}

			function logError(message) {
				console.error(`Five SecPay Client: ${message}`);
			}

			function resultEvent(message, error) {
				if (error) {
					logError(`Five SecPay Client: FAILED: ${message}`);
				} else {
					log(`Five SecPay Client: SUCCESS: ${message}`);
				}
				eventCallback(message, error);
			}

			function getFive9MetaData() {
				//alert('GetFive9MetaData');
				log('>>> getCallData');
				fetch('https://app.five9.com/appsvcs/rs/svc/auth/metadata', {
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors' // no-cors, cors, *same-origin.
				})
				.then(function(response) {
					log(`getFive9MetaData returned status ${response.status}`);
					if (response.status === 200) {
						return response.json();
					}
					//alert('Agent is not logged in');
					resultEvent('Agent is not logged in', true);
				})
				.then(jsonData => getCallData(jsonData))
				.catch(() => function(err) {
					logError(err);
					//alert('Agent is not logged in');
					resultEvent('Agent is not logged in', true);
				});
			}

			function getCallData(f9md) {
				log('>>> getCallData');
				activeF9md = f9md;
				fetch('https://' + f9md.metadata.dataCenters[0].apiUrls[0].host + '/appsvcs/rs/svc/agents/' + f9md.userId + '/interactions/calls', {
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors' // no-cors, cors, *same-origin.
				})
					.then(response => response.json())
					.then(function(calls) {
						log(`Calls:`);
						console.dir(calls);

						let activeCall = null;
						let activeCallTypes = ['TALKING']; // Only calls that are in talking state are allowed
						if (Array.isArray(calls) && calls.length > 0) {
							calls.forEach(function(call) {
								if (activeCallTypes.includes(call.state) && activeCall === null) {
									activeCall = call;
								}
							});
						}
						if (activeCall) {
							log(`Found active call ${activeCall}`);
							getCAVs(f9md, activeCall);
						} else {
							//alert('You are not on an active Five9 Call');
							resultEvent('You are not on an active Five9 Call', true);
						}
					}).catch((err) => {
						logError(err);
						//alert('You are not on an active Five9 Call');
						resultEvent('You are not on an active Five9 Call', true);
					});
			}

			function getCAVs(f9md, activeCall) {
				log('>>> getCAVs');
				fetch('https://' + f9md.metadata.dataCenters[0].apiUrls[0].host + '/appsvcs/rs/svc/orgs/' + f9md.orgId + '/call_variables', {
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors' // no-cors, cors, *same-origin.
				})
					.then(response => response.json())
					.then(function(theCavs) {
						log('CAVs:');
						console.dir(theCavs);

						cavs = theCavs;
						setCAVs(f9md, activeCall);
					}).catch((err) => {
						logError(err);
						//alert('Unable to fetch call variables');
						resultEvent('Unable to fetch call variables', true);
					});
			}

			function checkName(cav, name) {
				if (name) {
					let parts = name.split('.');
					if (parts.length >= 2) {
						if ((parts[0] === cav.group) && (parts[1] === cav.name)) {
							return true;
						}
					}
				}
				return false;
			}

			function getCAVbyName(name) {
				if (name) {
					return cavs.find((cav) => checkName(cav, name));
				}
				return undefined;
			}

			function getCAVpayload(ivrCAVs) {
				log('>>> getCAVpayload');
				let payload = {};
				ivrCAVs.forEach(function(cav) {
					let foundCAV = getCAVbyName(cav.name);
					if (foundCAV) {
						payload[foundCAV.id] = cav.value;
					} else {
						logError(`CAV ${cav.name} not found, did you create it in VCC?`);
					}
				});
				return payload;
			}

			function setCAVs(f9md, call) {
				log('>>> setCAVs');

				let cavPayload = getCAVpayload(cavsToPass);
				log(`CAV Payload: ${cavPayload}`);
				log(`CAV Payload JSON: ${JSON.stringify(cavPayload)}`);

				let url = 'https://' + f9md.metadata.dataCenters[0].apiUrls[0].host +  '/appsvcs/rs/svc/agents/' + f9md.userId + '/interactions/calls/' + call.id + '/variables';
				log(`'URL: ${url}`)
				fetch(url, {
					method: 'PUT',
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors', // no-cors, cors, *same-origin.
					headers: {
						'Content-Type': 'application/json; charset=utf-8'
					},
					body: JSON.stringify(cavPayload)
				})
				.then(function(response) {
					log(`setCavs returned status ${response.status}`);
					if (response.status === 200) {
						return response.json();
					}
					//alert('Could not set Call Attached Variables')
					resultEvent('Could not set Call Attached Variables', true);
				})
				.then((jsonData) => getCampaigns(f9md, call));
			}

			function checkCampaignName(campaign, name) {
				return campaign.name === name;
			}

			function getCampaignByName(name) {
				let foundCampaign = campaigns.find((campaign) => checkCampaignName(campaign, name));
				log(`getCampaignByName for ${name} returned ${foundCampaign}`);
				return foundCampaign;
			}

			function checkDefault(restriction) {
				log('>>> checkDefault');
				return restriction.type === 'DEFAULT_VALUE';
			}

			function getCAVdefaultValue(cav) {
				log('>>> getCAVdefaultValue');
				if (cav.restrictions) {
					let restrictions = cav.restrictions.restrictions;
					if (restrictions) {
						let restriction = restrictions.find((restriction) => checkDefault(restriction));
						if (restriction) {
							return restriction.value;
						}
					}
				}
				return undefined;
			}

			function getSecureCampaignName() {
				log('>>> getSecureCampaignName');
				let secureIVR = getCAVbyName(SECURE_IVR_CAV);
				if (secureIVR) {
					let campaignName = getCAVdefaultValue(secureIVR);
					if (campaignName) {
						//alert(`Returning campaign name ${campaignName}`);
						log(`Returning campaign name ${campaignName}`);
						return campaignName;
					}
					resultEvent('You must set the default value of CAV ' + SECURE_IVR_CAV + ' to be the secure campaign name in VCC!', true);
				} else {
					resultEvent('CAV ' + SECURE_IVR_CAV + ' not found, did you create it in VCC?', true);
				}
				return undefined;
			}

			function getCampaignIdByName(campaignName) {
				log('>>> getCampaignIdByName');
				if (campaignName) {
					let campaign = getCampaignByName(campaignName);
					if (campaign) {
						log(`Returning campaign id ${campaign.id}`);
						return campaign.id;
					}
				}
				return undefined;
			}

			function getCampaigns(f9md, call) {
				log('>>> getCampaigns');
				fetch('https://' + f9md.metadata.dataCenters[0].apiUrls[0].host + '/appsvcs/rs/svc/orgs/' +
					f9md.orgId + '/campaigns', {
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors' // no-cors, cors, *same-origin.
				})
				.then(response => response.json())
				.then(function(theCampaigns) {
					log(`Campaigns: `);
					console.dir(theCampaigns);
					campaigns = theCampaigns;

					let campaignName = getSecureCampaignName();
					log(`Secure Campaign Name: ${campaignName}`);

					let campaignId = getCampaignIdByName(campaignName);
					log(`Secure Campaign Id: ${campaignId}`);
					if (campaignId) {
						conferenceCall(f9md, campaignId, call);
					} else {
						resultEvent(
							`Campaign ${campaignName} is not found, check the default value of ${SECURE_IVR_CAV} in VCC and make sure it matches the name of your secure campaign.`,
							true);
					}
				}).catch((err) => {
					logError(err);
					resultEvent('Error getting campaigns', true);
				});
			}

			function conferenceCall(f9md, campaignId, call) {
				log('>>> conferenceCall');
				let payload = {'warm': false, campaignId: campaignId};
				let url = 'https://' + f9md.metadata.dataCenters[0].apiUrls[0].host + '/appsvcs/rs/svc/agents/' + f9md.userId +
					'/interactions/calls/' + call.id + '/add_campaign_to_conference';

				fetch(url, {
					method: 'POST',
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors', // no-cors, cors, *same-origin.
					headers: {
						'Content-Type': 'application/json; charset=utf-8'
					},
					body: JSON.stringify(payload)
				}).then(function(response) {
					log(`conferenceCall returned status ${response.status}`);
					if (response.status === 200) {
						resultEvent('SecurePay started successfully', false);
					} else {
						resultEvent('Could not complete conference', true);
					}
				})
			}

			function disconnectConferenceParticipant(f9md, confCall) {
				log('>>> disconnectConferenceParticipant');
				fetch('https://' + f9md.metadata.dataCenters[0].apiUrls[0].host + '/appsvcs/rs/svc/agents/' + f9md.userId + '/interactions/calls/' + confCall.id + '/disconnectConferenceParticipant', {
					method: 'PUT',
					cache: 'no-cache',
					credentials: 'include',
					mode: 'cors'
				})
				.then(response => response.json())
				.then(function(response) {
					log(`disconnectConferenceParticipant returned code ${response.status}`);
				}).catch((err) => {
					logError(err);
					resultEvent('You are not on an active Five9 Call', true);
				});
			}

			function cancelConference() {
				log('>>> cancelConference');
				if (activeF9md) {
					let f9md = activeF9md;
					fetch('https://' + f9md.metadata.dataCenters[0].apiUrls[0].host + '/appsvcs/rs/svc/agents/' + f9md.userId + '/interactions/calls', {
						cache: 'no-cache',
						credentials: 'include',
						mode: 'cors'
					})
					.then(response => response.json())
					.then(function(calls) {
						log('Calls:');
						console.dir(calls);
						let confCall = null;
						let activeCallTypes = ['CONFERENCE_PARTICIPANT_TALKING']; // Only talking conference participant
						if (Array.isArray(calls) && calls.length > 0) {
							calls.forEach(function(call) {
								if (activeCallTypes.includes(call.state) && confCall === null) {
									confCall = call;
								}
							});
						}
						if (confCall) {
							log('ConfCall:');
							console.dir(confCall);
							disconnectConferenceParticipant(f9md, confCall);
							resultEvent('Secure Pay canceled successfully', false);
						} else {
							resultEvent('SecurePay Not Active, no active conference', true);
						}
					}).catch((err) => {
						logError(err);
						resultEvent('You are not on an active Five9 Call', true);
					});
				} else {
					resultEvent('SecurePay Not Active', true);
				}
			}

			return {
				initialize: initialize,
				startSecurePayment: startSecurePayment,
				cancelSecurePayment: cancelSecurePayment
			}
		}) ()
		//export default secpay;
	</script>

    <!-- (2) The Five9 Bridge Client Library
        Provides methods and callbacks for connecting to the Bridge server to start a session and handling
        asyncronous session open, data received and session close events.
    -->
    <script type="text/javascript">
			/* eslint-disable no-console */
		/* eslint-disable no-unused-expressions */

		/**************************************************************************
		 * Five9BridgeClient Class
		 *
		 * This javascript Class is used in interface to the Five9 Bridge Server.
		 * The Five9 Bridge Server provides a realtime mechanism for posting messages
		 * to a client which are sent to the client via a WebSocket connection to
		 * the Bridge server.
		 *
		 * This class mainly supports the client's connection to Bridge Server and
		 * through callbacks, notifies the client of inbound messages and connection
		 * status.  It also provides utility functions that can be used to REST post
		 * messages to the client as well as delete the client connection via REST.
		 **************************************************************************/
		class Five9BridgeClient {

			//**************************************************************************
			constructor() {
				this.VERSION = '2.0.1';

				this.openEventCallback;
				this.dataEventCallback;
				this.closeEventCallback;
				this.closeError;

				this.socketURL;
				this.domain;
				this.websocket;
				this.restURL;

				// Setup a timer to send a ping message over the WebSocket every 30 seconds
				// eslint-disable-next-line @lwc/lwc/no-async-operation
				setInterval(() => {
						this._sendPing();
					},
					30000);
			}

			//**************************************************************************
			_sendPing() {
				if (!this.isOpen())
					return;

				if (this.websocket.readyState !== 1)
					return;

				let message = {
					type: "ping"
				};

				this._log("Sending PING on socket");
				try {
					let jsonMessage = JSON.stringify(message);
					this.websocket.send(jsonMessage);
				} catch (ex) {
					this._log("Failed sending ping");
					this._CloseWebSocketOnError("Failed trying to ping the Bridge Server");
				}
			}

			//**************************************************************************
			_log(message, error) {
				if (error) {
					console.error(`Five9 Bridge Client ERROR: ${message}`);
				} else {
					console.info(`Five9 Bridge Client: ${message}`);
				}
			}

			//**************************************************************************
			_connectWebSocket(wsURL) {
				this._log("WebSocket connect to: " + wsURL);
				try {
					this.websocket = new WebSocket(wsURL);
					this.websocket.onopen = evt => this._onOpen(evt);
					this.websocket.onclose = evt => this._onClose(evt);
					this.websocket.onmessage = evt => this._onMessage(evt);
					this.websocket.onerror = evt => this._onError(evt);
				} catch (ex) {
					this._log(`Five9 Bridge Client: Exception opening web socket: ${ex.message}`, true);
				}
			}

			//**************************************************************************
			_onOpen() {
				this._log("WebSocket opened, send our Domain to the Bridge Server");

				// Send this client's domain
				this._sendDomain();
			}

			//**************************************************************************
			_onClose() {
				this._log('Cleanup on connection closed');

				if (this.closeEventCallback) {
					this.closeEventCallback(this.closeError);
				}

				this.websocket = undefined;
				this.dataEventCallback = undefined;
				this.openEventCallback = undefined;
				this.closeEventCallback = undefined;
				this.closeError = undefined;
				this.restURL = undefined;
				this.socketURL = undefined;
				this.domain = undefined;
			}

			//**************************************************************************
			_onMessage(evt) {
				// this._log(evt.data);
				let message = JSON.parse(evt.data);
				let messageType = message.type;
				if (messageType === "data") {
					let messageData = JSON.stringify(message.data);
					this._log(`Data: ${messageData}`);

					// Notify the app
					this.dataEventCallback(messageData);
					return;
				}

				if (messageType === 'url') {
					this.restURL = message.data;
					this._log(`URL: ${this.restURL}`);

					// Did the app want to be notified?
					if (this.openEventCallback)
						this.openEventCallback(this.restURL);
					return;
				}

				if (messageType === 'error') {
					let errorMessage = message.message;
					this._log(`Error: ${errorMessage}`);

					// Notify the app
					this._CloseWebSocketOnError(errorMessage);
					return;
				}

				this._log(`Received unexpected Web Socket Message: ${messageType}`);
			}

			//**************************************************************************
			_onError() {
				// While onError delivers an event is doesn't give us the reason which is odd
				// because the socket code logs the reason for the failure.
				this._log(`ERROR: onError websocket callback`);
				this.closeError = new Error('Error trying to connect to the WebSocket URL');

				// Note, we don't close the socket as that is happening automatically when we get an error
			}

			//**************************************************************************
			_CloseWebSocketOnError(message) {
				this._log(`${message}: Closing WebSocket`, true);
				this.closeError = new Error(message);
				this.websocket.close();
			}

			//**************************************************************************
			_getDomainAndOpenSocket() {
				this._log(`Get the Five9 user domain and send to the bride server over the WebSocket`);
				this._getMetaData();
			}

			//**************************************************************************
			_getMetaData() {
				this._log(`Get Five9 MetaData (this validates the user is logged in)`);
				fetch('https://app.five9.com/appsvcs/rs/svc/auth/metadata', {
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors' // no-cors, cors, *same-origin.
				})
					.then(response => {
						// Good Response
						if (response.status === 200) {
							this._log('OK (200) response getting Five9 Metadata');
							return response.json();
						}

						// Authentication Failure
						if (response.status === 401) {
							this.closeError = new Error(`User is not logged in`);
							this._onClose();
							return;
						}

						this.closeError = new Error(`Failed trying to get Five9 Metadata, response status: ${response.status}`);
						this._onClose();
					})
					.then(f9md => this._getOrgInfo(f9md))
					.catch(err => {
						this.closeError = new Error(`Exception trying to get Five9 Metadata: ${err.message}`);
						this._onClose();
					});
			}

			//*******************************************************
			_getOrgInfo(f9md) {
				let orgId = f9md.orgId;

				let host = f9md.metadata.dataCenters[0].apiUrls[0].host;
				let port = f9md.metadata.dataCenters[0].apiUrls[0].port;
				let baseURL = `https://${host}:${port}`;
				let url = `${baseURL}/appsvcs/rs/svc/orgs/${orgId}`;

				this._log(`Get Five9 Org information for org Id: ${orgId}`);
				fetch(url, {
					cache: 'no-cache',
					credentials: 'include', // include, same-origin, *omit
					mode: 'cors' // no-cors, cors, *same-origin.
				})
					.then(response => {
						if (response.status === 200) {
							this._log('OK (200) response getting Five9 Org Info');
							return response.json();
						}
						this.closeError = new Error(`Failed trying to get Five9 Org info, response status: ${response.status}`);
						this._onClose();
					})
					.then( orgInfo => {
						this.domain = orgInfo.name;
						this._log(`"Got domain ${this.domain}, open the WebSocket to the Bridge Server`);
						this._connectWebSocket(this.socketURL);
					})
					.catch(err => {
						this.closeError = new Error(`Exception trying to get Five9 Org Info: ${err.message}`);
						this._onClose();
					})
			}

			//*******************************************************
			_sendDomain() {
				// OK, here is where we send our domain to the server over the web socket
				let message = {
					type: "domain",
					data: this.domain
				};

				try {
					let jsonMessage = JSON.stringify(message);
					this.websocket.send(jsonMessage);
				} catch (ex) {
					this._CloseWebSocketOnError(`Failed trying send domain to Bridge Server`);
				}
			}


			//**************************************************************************
			// External Interface
			//**************************************************************************

			//**************************************************************************
			open(dataEvent, openEvent, closeEvent, wsURL) {
				//alert('abrindo bridge');
				if (this.isOpen()) {
					throw(new Error('Open WebSocket called but websocket already open'));
				}

				// Make sure they passed the callbacks.
				if (!dataEvent) {
					throw(new Error('Open WebSocket called without dataEvent callback set'));
				}
				if (!openEvent) {
					throw(new Error('Open WebSocket called without openEvent callback set'));
				}
				if (!closeEvent) {
					throw(new Error('Open WebSocket called without closeEvent callback set'));
				}

				// If they don't pass a URL then use the default
				if (!wsURL) {
					wsURL = "wss://psapps002.atl.five9.com/ps-bridge/bridge";
				}

				// Save the socket URL we should use
				this.socketURL = wsURL;

				// Save the callback functions
				this.dataEventCallback = dataEvent;
				this.openEventCallback = openEvent;
				this.closeEventCallback = closeEvent;
				this.closeError = undefined;

				// Get this client's domain and then open the WebSocket to the Bridge server and send it.
				this._getDomainAndOpenSocket();
			}

			//**************************************************************************
			close() {
				if (!this.isOpen()) {
					throw(new Error('Close WebSocket called but websocket not open'));
				}
				this._log('Close WebSocket');
				this.closeError = undefined;
				this.websocket.close();
			}

			//**************************************************************************
			isOpen() {
				return (!!this.websocket);
			}

			//**************************************************************************
			postToBridge(jsonData, callbackResult) {
				let xhr = new XMLHttpRequest();

				xhr.open('POST', this.restURL);
				xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
				xhr.onreadystatechange = () => {
					if (xhr.readyState === XMLHttpRequest.DONE) {
						if (callbackResult) {
							callbackResult(xhr.status, xhr.responseText);
						}
					}
				};
				xhr.send(jsonData);
			}

			//**************************************************************************
			deleteToBridge(callbackResult) {
				let xhr = new XMLHttpRequest();

				xhr.open('DELETE', this.restURL);
				xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
				xhr.onreadystatechange = () => {
					if (xhr.readyState === XMLHttpRequest.DONE) {
						if (callbackResult) {
							callbackResult(xhr.status, xhr.responseText);
						}
					}
				};
				xhr.send();
			}

			//**************************************************************************
			getVersion() {
				return this.VERSION;
			}
		}

		//export { Five9BridgeClient };

		/**************************************************************************
		 *
		 * ----------------------- Five9BridgeClient END --------------------------
		 *
		 **************************************************************************/
	</script>
    <!-- ============= -->
    <!-- Sample's code -->
    <!-- ============= -->
    <script type="text/javascript">
	var uicontroller = (function () {
    // CAV to pass the client's unique Bridge REST request URL.
    // NOTE that the IVR must reference the same CAV name.
    // Also, any campaigns must add this and any application specific CAVs to their Campaign Profile's Layout in the Five9 Admin
    const cavBridgeURL = "SecurePay.BridgeURL";
    var callVariables;

    function logInfo(msg) {
        console.info("SecurePaymentSimple: " + msg);
    }

    function logError(msg) {
        console.error("SecurePaymentSimple: " + msg);
    }

    // Method to show Bridge Messages on our web form
    function showBridgeMessage(message, error) {
        //alert('showbridgemessage');
		if (error) {
            logError(message);
        } else {
            logInfo(message);
        }
        $("#bridge-message").html("<strong>Navegação:</strong> " + message);
    }

    // Method to show Bridge Result on our web form
    function showBridgeResult(result) {
        logInfo(result);
        $("#bridge-result").html("<strong>Resultado:</strong> " + result);


        if (result === 'Cartão sucesso') {
            // Checkbox
            $('#inlineCheckbox1').css('background-color', 'purple')
            $('#inlineCheckbox1').prop("checked", true)

            // Line
            $('#line_1').css("border-color", 'purple')
            $('#line_1').css("background-color", 'purple')
        }

        if (result === 'Data correta') {
            // Checkbox
            $('#inlineCheckbox2').css('background-color', 'purple')
            $('#inlineCheckbox2').prop("checked", true)

            // Line
            $('#line_2').css("border-color", 'purple')
            $('#line_2').css("background-color", 'purple')
        }

        if (result === 'CVV Correto') {
            // Checkbox
            $('#inlineCheckbox3').css('background-color', 'purple')
            $('#inlineCheckbox3').prop("checked", true)
        }
    }

    function _onOpenEvent(bridgeURL) {
        // OK, if we get our URL then we be rolling!
        // Lets see if we can send a message to ourselves...
        //alert('onopenevent');
		logInfo(`WebSocket Opened, Session URL: ${bridgeURL}, initiate secure payment IVR connection`);

        // Add the Bridge Server's URL as a CAV.
        // The script will use this to send real time messages and any other desired information back to this client
        // by POSTing JSON formatted messages to this URL.  This URL uniquely identifies this client's session.
        callVariables.push({name: cavBridgeURL, value: bridgeURL});

        // Now start the secure payment
        secpay.startSecurePayment(callVariables);
    }

    function _onDataEvent(jsonData) {
        let eventData = JSON.parse(jsonData);

        // Is this a message?
        if (eventData.type === "message") {
            // Yes, show the status
            let message = eventData.message;
            showBridgeMessage(message, false);
        }

        // Is this a result?
        else if (eventData.type === 'result') {
            // Yes, show the result
            let result = eventData.result;
            showBridgeResult(result);
        }
    }

    function _onCloseEvent(error) {
        if (error) {
            showBridgeMessage(error.message, true);
        }
        logInfo("WebSocket closed");
    }

    // Method used to initiate the secure payment
    function _initiateSecurePayment(withEvents) {
        // Get our data
		//alert('Initiate Secure Payment');
        const firstName = $('#firstName').val();
        const lastName = $('#lastName').val();
        const invoiceNumber = $('#invoiceNumber').val();
        const paymentAmount = $('#paymentAmount').val();
        const lang = $("input:radio[name='lang']:checked").val();
		const F9Bridge = new Five9BridgeClient();
		//alert(firstName + lastName + invoiceNumber + paymentAmount);
        // Set the CAVs
        callVariables = [
            {name: 'SecurePay.FirstName', value: lang},
            {name: 'SecurePay.LastName', value: lastName},
            {name: 'SecurePay.InvoiceNumber', value: invoiceNumber},
            {name: 'SecurePay.PaymentAmount', value: paymentAmount},
            {name: 'SecurePay.Lang', value: lang}
        ];
        // console.log(callVariables);
        // // Are we using the Five9 Bridge Service to get events?
        if (withEvents) {
			//alert("with events");
            // We don't know when to close our socket IF we made a sec payment request as we don't get an event when it completes.
            // So, we always check for and close here if needed.  Right now we don't provide any feedback as this would only
            // happen if the script didn't send a DELETE at the end of a previous secure payment IVR session which is a defect.
            if (F9Bridge.isOpen()) {
				//alert("Bridge found open, closing");
                logError("Bridge found open, closing");
                F9Bridge.close();
                return;
            }

            // Now kick things off by opening the bridge client.  The callbacks will drive us forward and once the socket is open and connected
            // we'll go ahead and start the secure payment call process.
            try {
                // Parms: wsURL, dataEvent, urlEvent, closeEvent
                // this.five9bridgeclient.open(this._onDataEvent, this._onOpenEvent, this._onCloseEvent, "ws://localhost:5555/bridge");
                F9Bridge.open(_onDataEvent, _onOpenEvent, _onCloseEvent);
            } catch (ex) {
                showBridgeMessage(ex.message);
            }
        } else {
            //secpay.startSecurePayment(callVariables);
			//alert('no events');
			secpay.startSecurePayment(callVariables);
        }
    }

	function clearCheckbox() {
		// Checkbox
		$('#inlineCheckbox1').css('background-color', '')
		$('#inlineCheckbox1').prop("checked", false)

		// Line
		$('#line_1').css("border-color", '')
		$('#line_1').css("background-color", '')

		// Checkbox
		$('#inlineCheckbox1').css('background-color', '')
		$('#inlineCheckbox1').prop("checked", false)

		// Line
		$('#line_2').css("border-color", '')
		$('#line_2').css("background-color", '')

		$('#inlineCheckbox2').css('background-color', '')
		$('#inlineCheckbox2').prop("checked", false)

		// Chekbox
		$('#inlineCheckbox3').css('background-color', '')
		$('#inlineCheckbox3').prop("checked", false)
	}

    function init() {
		//alert('init');
        logInfo(">>> Init");

        // Create the button handlers
        $("#btn-start").click(function() {
			//alert('Start button pressed');
            logInfo("Start button pressed");
            _initiateSecurePayment(false);
        });

        $("#btn-start-with-events").click(function() {
			//alert('Start with events button pressed');
            logInfo("Start with Event button pressed");
			clearCheckbox();
            _initiateSecurePayment(true);
        });

        $("#btn-cancel").click(function() {
            logInfo("Cancel button pressed");
			let result = "Pagamento cancelado";
			let message = "Navegação interrompida pelo agente";
            showBridgeResult(result);
			showBridgeMessage(message);
            secpay.cancelSecurePayment();
        });

        // We want to initialize the five9secpay library
        //initialize(_onResult);
    }

    function _onResult(message, error) {
		showBridgeMessage(message, error);

        // Did we get an error?
        if (error) {
            logError("Failed: " + message);

            // If we failed to start the sec pay conference then we need to close the
            // Bridge Web Socket if we have a session open
            if (Five9BridgeClient.isOpen()) {
                Five9BridgeClient.close();
            }
            return;
        }

        logInfo("Success: " + message);
    }

    return {
        init: init,
    };
}) ();
</script>

    <style>
        #content {
            width: 80%;
            height: 50%;
            background-color: #e7e5e5;
            border: 5px solid #d5cbcb;
        }

        .line {
            width: 75px;
            height: 0;
            border: 2px solid #9f9f9f;
            background-color: #9f9f9f;
        }

        #contente-information {
            width: 80%;
            height: 50%;
            background-color: rebeccapurple;
            border-radius: 15px;
        }

        #contente-information-down {
            /*width: 400px;*/
            height: 77%;
            border-top-right-radius: 15px;
            border-top-left-radius: 15px;
            background-color: #fd2196;
        }

    </style>
</head>

<body>

<div class="container-fluid">
    <div style="margin-bottom: -35px">
        <!-- <svg style="width: 150px" id="Capa_1" data-name="Capa 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 264.62 88.78"><defs><style>.cls-1{fill:none;}.cls-2{font-size:92.42px;fill:#762281;font-family:MullerNarrow-Bold, Muller Narrow;font-weight:700;}.cls-3{letter-spacing:-0.03em;}.cls-4{font-size:68.46px;fill:#1d1e1b;font-family:SancoaleSoftenedRegular, Sancoale Softened;letter-spacing:-0.05em;}.cls-5{clip-path:url(#clip-path);}.cls-6{fill:url(#Degradado_sin_nombre_4);}.cls-7{opacity:0.1;}.cls-8{fill:#d4ceff;}</style><clipPath id="clip-path"><rect class="cls-1" x="-552.51" width="500" height="290"/></clipPath><linearGradient id="Degradado_sin_nombre_4" x1="-35.58" y1="-209.04" x2="-34.58" y2="-209.04" gradientTransform="matrix(1365.9, 0, 0, -294.06, 48018.65, -61324.34)" gradientUnits="userSpaceOnUse"><stop offset="0.4" stop-color="#3047b0"/><stop offset="1" stop-color="#0087ff"/></linearGradient></defs><text class="cls-2" transform="translate(9.69 77.01)"><tspan class="cls-3">T</tspan><tspan x="43.81" y="0">P</tspan></text><text class="cls-4" transform="translate(115.29 77.01)">wallet</text><g class="cls-5"><rect id="Rectángulo_303" data-name="Rectángulo 303" class="cls-6" x="-583.93" y="-0.45" width="1365.9" height="294.06"/><g id="Grupo_2948" data-name="Grupo 2948" class="cls-7"><g id="Grupo_2943" data-name="Grupo 2943"><path id="Trazado_59" data-name="Trazado 59" class="cls-8" d="M3.12,77.57A4.44,4.44,0,1,1,7.55,82,4.43,4.43,0,0,1,3.12,77.57Zm1.14,0a3.3,3.3,0,1,0,3.29-3.29A3.29,3.29,0,0,0,4.26,77.57Z"/></g><g id="Grupo_2944" data-name="Grupo 2944"><path id="Trazado_66" data-name="Trazado 66" class="cls-8" d="M10.85,16.19a2.55,2.55,0,1,0,2.55-2.55,2.55,2.55,0,0,0-2.55,2.55Z"/><path id="Trazado_67" data-name="Trazado 67" class="cls-8" d="M10.28,16.19a3.12,3.12,0,1,1,3.12,3.12h0A3.12,3.12,0,0,1,10.28,16.19Zm1.14,0a2,2,0,1,0,2-2A2,2,0,0,0,11.42,16.19Z"/></g><path id="Trazado_77" data-name="Trazado 77" class="cls-8" d="M-582.94,33.91A166,166,0,0,1-536.77,1.29l.53-.26L-541.73-9.7A179.21,179.21,0,0,1-481-27.94l1.54,13.74.55-.05A167.53,167.53,0,0,1-400.74-1.82l.35.15,37-37h72.9l55.25,55.26H3.69V15.45H-234.76L-290-39.8h-73.84L-400.65-3a168.6,168.6,0,0,0-77.8-12.42L-480-29.2l-.56.06a180.36,180.36,0,0,0-62.18,18.69l-.51.26,5.47,10.7a166.92,166.92,0,0,0-46,32.6Z"/><path id="Trazado_90" data-name="Trazado 90" class="cls-8" d="M331,281.7h4.56V284H331Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.57V284h-4.57Zm10.27,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.27,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.27,0h2l.6-1,2,1.14L457.5,284h-3.35Zm-131.66-3,2-1.14,2.28,4-2,1.14Zm137.15-2.94,2.28-4,2,1.14-2.28,3.95Zm-142.27-6,2-1.14,2.28,3.95-2,1.14Zm147.4-2.94,2.28-3.95,2,1.14-2.28,4Zm-152.53-5.95,2-1.14,2.28,3.95-2,1.14Zm157.66-2.94,2.28-3.95,2,1.14-2.28,3.95ZM307.11,252l2-1.15,2.28,4-2,1.14ZM475,249.06l2.28-3.95,2,1.14L477,250.2ZM302,243.11l2-1.14,2.28,3.95-2,1.14Zm178.19-2.94,2.28-3.95,2,1.14-2.28,3.95Zm-183.32-5.95,2-1.14L301.1,237l-2,1.14Zm188.45-2.94,2.28-3.95,2,1.14-2.28,3.95Zm-193.58-5.95,2-1.14,2.28,3.95-2,1.14Zm198.71-2.94,2.28-3.95,2,1.14-2.28,3.95Zm-203.85-5.95,2-1.14,2.28,3.95-2,1.14Zm209-2.94,2.29-3.95,2,1.14-2.28,4Zm-214.11-5.94,2-1.14,2.28,3.94-2,1.15Zm219.25-2.94,2.28-4,2,1.14-2.28,3.95Zm-224.38-6,2-1.14,2.28,3.95-2,1.14Zm229.51-2.94,2.28-3.95,2,1.14-2.28,4Zm-234.64-5.95,2-1.14,2.28,4-2,1.14ZM511,186.84l2.28-4,2,1.14L512.93,188Zm-244.9-6,2-1.14,2.28,3.95-2,1.14Zm250-2.94,2.28-3.95,2,1.14-2.28,4ZM260.93,172l2-1.15,2.28,4-2,1.14Zm257.9-5.75,2-1.14,2.28,3.95-2,1.14Zm-257-.33,2.28-4,2,1.14-2.28,3.95Zm251.9-8.56,2-1.14,2.28,4-2,1.14ZM266.94,157l2.28-3.95,2,1.14-2.28,4Zm241.64-8.56,2-1.14,2.28,3.95-2,1.14Zm-236.5-.33,2.28-4,2,1.14-2.28,3.95Zm231.37-8.56,2-1.14,2.29,4-2,1.14Zm-226.24-.32,2.28-4,2,1.14-2.28,3.95Zm221.11-8.56,2-1.14,2.28,4-2,1.14Zm-216-.33,2.28-3.95,2,1.14-2.28,4Zm210.84-8.56,2-1.14,2.28,3.95-2,1.14Zm-205.72-.33,2.28-3.95,2,1.14-2.28,3.95Zm200.59-8.56,2-1.14,2.28,3.95-2,1.14Zm-195.46-.33,2.28-3.95,2,1.14-2.28,3.95ZM482.91,104l2-1.14,2.28,3.95-2,1.14Zm-185.19-.33L300,99.76l2,1.14-2.28,3.95Zm180.06-8.56,2-1.14L482,98l-2,1.14Zm-174.93-.33,2.28-3.94,2,1.14-2.28,4Zm169.8-8.55,2-1.14,2.28,4-2,1.14ZM308,85.94l2.28-4,2,1.14-2.28,4Zm159.53-8.56,2-1.14,2.28,4-2,1.14Zm-154.4-.33,2.28-4,2,1.14-2.28,4Zm149.27-8.56,2-1.14,2.28,4-2,1.15Zm-144.14-.33,2.28-3.94,2,1.14-2.28,4Zm139-8.56,2-1.14,2.28,3.95-2,1.14Zm-133.88-.33,2.2-3.8h5.22v2.29h-3.9l-1.53,2.66Zm13.12-3.8h4.56v2.29H336.5Zm10.26,0h4.57v2.29h-4.57Zm10.27,0h4.56v2.29H357Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29h-4.56Zm10.27,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29H418.6Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.57v2.29h-4.57Zm10.27,0H454v2.29h-4.56Z"/><path id="Trazado_93" data-name="Trazado 93" class="cls-8" d="M71.41,174.12H76v2.28H71.41Zm10.27,0h4.56v2.28H81.68Zm10.26,0H96.5v2.28H91.94Zm10.26,0h4.56v2.28H102.2Zm10.26,0H117v2.28h-4.57Zm10.27,0h4.56v2.28h-4.56Zm10.26,0h4.56v2.28H133Zm10.26,0h4.56v2.28h-4.56Zm10.26,0h4.56v2.28h-4.56Zm-89.48-5L66,168l2.28,3.95-2,1.14Zm97.1,2.69,2.28-3.95,2,1.14-2.28,4ZM58.9,160.25l2-1.14,2.28,3.95-2,1.14Zm107.36,2.68,2.28-4,2,1.14-2.28,4ZM53.77,151.36l2-1.14L58,154.17l-2,1.14ZM171.39,154l2.28-3.95,2,1.14-2.28,4ZM48.64,142.47l2-1.14,2.29,3.95-2,1.14Zm127.88,2.69,2.28-3.95,2,1.14-2.28,4Zm-133-11.58,2-1.14,2.28,3.95-2,1.14Zm138.14,2.69,2.28-4,2,1.14-2.28,3.95ZM38.38,124.69l2-1.14,2.28,4-2,1.14Zm148.4,2.69,2.29-3.95,2,1.14-2.28,4ZM33.24,115.81l2-1.14,2.28,4-2,1.14Zm158.67,2.68,2.29-3.95,2,1.14-2.28,3.95ZM28.11,106.92l2-1.14,2.28,4-2,1.14Zm168.94,2.69,2.28-4,2,1.14-2.28,4ZM23,98l2-1.14,2.28,4-2,1.14Zm179.2,2.69,2.28-4,2,1.14-2.28,4ZM24.1,92l2.28-4,2,1.14-2.28,4ZM200.91,89l2-1.14,2.28,4-2,1.14Zm-171.68-6,2.28-3.95,2,1.14-2.28,3.95Zm166.55-2.94,2-1.14,2.28,4-2,1.14ZM34.37,74.18l2.28-4,2,1.14-2.28,3.95Zm156.28-2.93,2-1.14,2.28,4-2,1.14Zm-151.15-6,2.28-4,2,1.14-2.28,4Zm146-2.93,2-1.14,2.28,4-2,1.14Zm-140.89-6,2.28-3.95,2,1.14L46.6,57.54Zm135.76-2.93,2-1.14,2.28,4-2,1.14ZM49.76,47.52l2.28-4,2,1.14-2.28,3.95Zm125.5-2.94,2-1.14,2.29,4-2,1.14ZM54.89,38.63l2.28-4,2,1.14-2.28,4ZM170.13,35.7l2-1.15,2.28,4-2,1.14ZM60,29.74l2.28-3.95,2,1.14L62,30.88Zm105-2.93,2-1.14,2.28,3.95-2,1.14Zm-99.85-6,2.28-4,1.46.84V15.52h4.56V17.8H69l.42.24L67.13,22Zm94.71-2.93,2-1.14,2.28,3.95-2,1.14Zm-80.71-2.4h4.56V17.8H79.15Zm10.26,0H94V17.8H89.41Zm10.26,0h4.57V17.8H99.67Zm10.27,0h4.56V17.8h-4.56Zm10.26,0h4.56V17.8H120.2Zm10.26,0H135V17.8h-4.56Zm10.26,0h4.57V17.8h-4.57Zm10.27,0h4.56V17.8H151Z"/></g></g></;svg> -->
		<!-- <svg id="Capa_1" data-name="Capa 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 264.62 88.78"><defs><style>.cls-1{fill:none;}.cls-2{font-size:92.42px;fill:#762281;font-family:MullerNarrow-Bold, Muller Narrow;font-weight:700;}.cls-3{letter-spacing:-0.03em;}.cls-4{font-size:68.46px;fill:#1d1e1b;font-family:SancoaleSoftenedRegular, Sancoale Softened;letter-spacing:-0.05em;}.cls-5{clip-path:url(#clip-path);}.cls-6{fill:url(#Degradado_sin_nombre_4);}.cls-7{opacity:0.1;}.cls-8{fill:#d4ceff;}</style><clipPath id="clip-path"><rect class="cls-1" x="-552.51" width="500" height="290"/></clipPath><linearGradient id="Degradado_sin_nombre_4" x1="-35.58" y1="-209.04" x2="-34.58" y2="-209.04" gradientTransform="matrix(1365.9, 0, 0, -294.06, 48018.65, -61324.34)" gradientUnits="userSpaceOnUse"><stop offset="0.4" stop-color="#3047b0"/><stop offset="1" stop-color="#0087ff"/></linearGradient></defs><text class="cls-2" transform="translate(9.69 77.01)"><tspan class="cls-3">T</tspan><tspan x="43.81" y="0">P</tspan></text><text class="cls-4" transform="translate(115.29 77.01)">wallet</text><g class="cls-5"><rect id="Rectángulo_303" data-name="Rectángulo 303" class="cls-6" x="-583.93" y="-0.45" width="1365.9" height="294.06"/><g id="Grupo_2948" data-name="Grupo 2948" class="cls-7"><g id="Grupo_2943" data-name="Grupo 2943"><path id="Trazado_59" data-name="Trazado 59" class="cls-8" d="M3.12,77.57A4.44,4.44,0,1,1,7.55,82,4.43,4.43,0,0,1,3.12,77.57Zm1.14,0a3.3,3.3,0,1,0,3.29-3.29A3.29,3.29,0,0,0,4.26,77.57Z"/></g><g id="Grupo_2944" data-name="Grupo 2944"><path id="Trazado_66" data-name="Trazado 66" class="cls-8" d="M10.85,16.19a2.55,2.55,0,1,0,2.55-2.55,2.55,2.55,0,0,0-2.55,2.55Z"/><path id="Trazado_67" data-name="Trazado 67" class="cls-8" d="M10.28,16.19a3.12,3.12,0,1,1,3.12,3.12h0A3.12,3.12,0,0,1,10.28,16.19Zm1.14,0a2,2,0,1,0,2-2A2,2,0,0,0,11.42,16.19Z"/></g><path id="Trazado_77" data-name="Trazado 77" class="cls-8" d="M-582.94,33.91A166,166,0,0,1-536.77,1.29l.53-.26L-541.73-9.7A179.21,179.21,0,0,1-481-27.94l1.54,13.74.55-.05A167.53,167.53,0,0,1-400.74-1.82l.35.15,37-37h72.9l55.25,55.26H3.69V15.45H-234.76L-290-39.8h-73.84L-400.65-3a168.6,168.6,0,0,0-77.8-12.42L-480-29.2l-.56.06a180.36,180.36,0,0,0-62.18,18.69l-.51.26,5.47,10.7a166.92,166.92,0,0,0-46,32.6Z"/><path id="Trazado_90" data-name="Trazado 90" class="cls-8" d="M331,281.7h4.56V284H331Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.57V284h-4.57Zm10.27,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.27,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.26,0h4.56V284h-4.56Zm10.27,0h2l.6-1,2,1.14L457.5,284h-3.35Zm-131.66-3,2-1.14,2.28,4-2,1.14Zm137.15-2.94,2.28-4,2,1.14-2.28,3.95Zm-142.27-6,2-1.14,2.28,3.95-2,1.14Zm147.4-2.94,2.28-3.95,2,1.14-2.28,4Zm-152.53-5.95,2-1.14,2.28,3.95-2,1.14Zm157.66-2.94,2.28-3.95,2,1.14-2.28,3.95ZM307.11,252l2-1.15,2.28,4-2,1.14ZM475,249.06l2.28-3.95,2,1.14L477,250.2ZM302,243.11l2-1.14,2.28,3.95-2,1.14Zm178.19-2.94,2.28-3.95,2,1.14-2.28,3.95Zm-183.32-5.95,2-1.14L301.1,237l-2,1.14Zm188.45-2.94,2.28-3.95,2,1.14-2.28,3.95Zm-193.58-5.95,2-1.14,2.28,3.95-2,1.14Zm198.71-2.94,2.28-3.95,2,1.14-2.28,3.95Zm-203.85-5.95,2-1.14,2.28,3.95-2,1.14Zm209-2.94,2.29-3.95,2,1.14-2.28,4Zm-214.11-5.94,2-1.14,2.28,3.94-2,1.15Zm219.25-2.94,2.28-4,2,1.14-2.28,3.95Zm-224.38-6,2-1.14,2.28,3.95-2,1.14Zm229.51-2.94,2.28-3.95,2,1.14-2.28,4Zm-234.64-5.95,2-1.14,2.28,4-2,1.14ZM511,186.84l2.28-4,2,1.14L512.93,188Zm-244.9-6,2-1.14,2.28,3.95-2,1.14Zm250-2.94,2.28-3.95,2,1.14-2.28,4ZM260.93,172l2-1.15,2.28,4-2,1.14Zm257.9-5.75,2-1.14,2.28,3.95-2,1.14Zm-257-.33,2.28-4,2,1.14-2.28,3.95Zm251.9-8.56,2-1.14,2.28,4-2,1.14ZM266.94,157l2.28-3.95,2,1.14-2.28,4Zm241.64-8.56,2-1.14,2.28,3.95-2,1.14Zm-236.5-.33,2.28-4,2,1.14-2.28,3.95Zm231.37-8.56,2-1.14,2.29,4-2,1.14Zm-226.24-.32,2.28-4,2,1.14-2.28,3.95Zm221.11-8.56,2-1.14,2.28,4-2,1.14Zm-216-.33,2.28-3.95,2,1.14-2.28,4Zm210.84-8.56,2-1.14,2.28,3.95-2,1.14Zm-205.72-.33,2.28-3.95,2,1.14-2.28,3.95Zm200.59-8.56,2-1.14,2.28,3.95-2,1.14Zm-195.46-.33,2.28-3.95,2,1.14-2.28,3.95ZM482.91,104l2-1.14,2.28,3.95-2,1.14Zm-185.19-.33L300,99.76l2,1.14-2.28,3.95Zm180.06-8.56,2-1.14L482,98l-2,1.14Zm-174.93-.33,2.28-3.94,2,1.14-2.28,4Zm169.8-8.55,2-1.14,2.28,4-2,1.14ZM308,85.94l2.28-4,2,1.14-2.28,4Zm159.53-8.56,2-1.14,2.28,4-2,1.14Zm-154.4-.33,2.28-4,2,1.14-2.28,4Zm149.27-8.56,2-1.14,2.28,4-2,1.15Zm-144.14-.33,2.28-3.94,2,1.14-2.28,4Zm139-8.56,2-1.14,2.28,3.95-2,1.14Zm-133.88-.33,2.2-3.8h5.22v2.29h-3.9l-1.53,2.66Zm13.12-3.8h4.56v2.29H336.5Zm10.26,0h4.57v2.29h-4.57Zm10.27,0h4.56v2.29H357Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29h-4.56Zm10.27,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.56v2.29H418.6Zm10.26,0h4.56v2.29h-4.56Zm10.26,0h4.57v2.29h-4.57Zm10.27,0H454v2.29h-4.56Z"/><path id="Trazado_93" data-name="Trazado 93" class="cls-8" d="M71.41,174.12H76v2.28H71.41Zm10.27,0h4.56v2.28H81.68Zm10.26,0H96.5v2.28H91.94Zm10.26,0h4.56v2.28H102.2Zm10.26,0H117v2.28h-4.57Zm10.27,0h4.56v2.28h-4.56Zm10.26,0h4.56v2.28H133Zm10.26,0h4.56v2.28h-4.56Zm10.26,0h4.56v2.28h-4.56Zm-89.48-5L66,168l2.28,3.95-2,1.14Zm97.1,2.69,2.28-3.95,2,1.14-2.28,4ZM58.9,160.25l2-1.14,2.28,3.95-2,1.14Zm107.36,2.68,2.28-4,2,1.14-2.28,4ZM53.77,151.36l2-1.14L58,154.17l-2,1.14ZM171.39,154l2.28-3.95,2,1.14-2.28,4ZM48.64,142.47l2-1.14,2.29,3.95-2,1.14Zm127.88,2.69,2.28-3.95,2,1.14-2.28,4Zm-133-11.58,2-1.14,2.28,3.95-2,1.14Zm138.14,2.69,2.28-4,2,1.14-2.28,3.95ZM38.38,124.69l2-1.14,2.28,4-2,1.14Zm148.4,2.69,2.29-3.95,2,1.14-2.28,4ZM33.24,115.81l2-1.14,2.28,4-2,1.14Zm158.67,2.68,2.29-3.95,2,1.14-2.28,3.95ZM28.11,106.92l2-1.14,2.28,4-2,1.14Zm168.94,2.69,2.28-4,2,1.14-2.28,4ZM23,98l2-1.14,2.28,4-2,1.14Zm179.2,2.69,2.28-4,2,1.14-2.28,4ZM24.1,92l2.28-4,2,1.14-2.28,4ZM200.91,89l2-1.14,2.28,4-2,1.14Zm-171.68-6,2.28-3.95,2,1.14-2.28,3.95Zm166.55-2.94,2-1.14,2.28,4-2,1.14ZM34.37,74.18l2.28-4,2,1.14-2.28,3.95Zm156.28-2.93,2-1.14,2.28,4-2,1.14Zm-151.15-6,2.28-4,2,1.14-2.28,4Zm146-2.93,2-1.14,2.28,4-2,1.14Zm-140.89-6,2.28-3.95,2,1.14L46.6,57.54Zm135.76-2.93,2-1.14,2.28,4-2,1.14ZM49.76,47.52l2.28-4,2,1.14-2.28,3.95Zm125.5-2.94,2-1.14,2.29,4-2,1.14ZM54.89,38.63l2.28-4,2,1.14-2.28,4ZM170.13,35.7l2-1.15,2.28,4-2,1.14ZM60,29.74l2.28-3.95,2,1.14L62,30.88Zm105-2.93,2-1.14,2.28,3.95-2,1.14Zm-99.85-6,2.28-4,1.46.84V15.52h4.56V17.8H69l.42.24L67.13,22Zm94.71-2.93,2-1.14,2.28,3.95-2,1.14Zm-80.71-2.4h4.56V17.8H79.15Zm10.26,0H94V17.8H89.41Zm10.26,0h4.57V17.8H99.67Zm10.27,0h4.56V17.8h-4.56Zm10.26,0h4.56V17.8H120.2Zm10.26,0H135V17.8h-4.56Zm10.26,0h4.57V17.8h-4.57Zm10.27,0h4.56V17.8H151Z"/></g></g></svg> -->
		<img src="../img/tp wallet-60.jpg" alt="Logo" style="width: 150px;">
		<div style="margin-top: 10px;float: right;width: auto;font-size: x-large;">
			<input id="lang-es" name="lang" type="radio" value="ES" checked>
			<label for="lang-es">ES</label>
			<input id="lang-en" name="lang" type="radio" value="EN" style="margin-left: 8px;">
			<label for="lang-en">EN</label>
		</div>
	</div>
    <div class="row align-items-md-stretch mt-5 g-lg-0" style="height: 600px">
        <div class="col-md-5">
            <div class="h-100 p-5 bg-light rounded-3 border border-2 border-start-0 border-bottom-0">
                <h3 class="text-center fw-bold" style="margin-bottom: 60px">Transaction Information</h3>
                <div id="contente-information" class="mx-auto">
                    <div id="contente-information-down">
                        <div class="ms-4 text-light p-4">
                            Transaction ID:
                            <div class="fs-4" id="text">
                                <!-- @Call.call_id@ -->
								{{$call_id}}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-5">
                    <!-- <svg style="width: 200px" id="Capa_1" data-name="Capa 1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 271 70.98"><defs><style>.cls-1{fill:none;}.cls-2{clip-path:url(#clip-path);}.cls-3{opacity:0.1;}.cls-4{fill:#1d1d1b;}.cls-5{clip-path:url(#clip-path-2);}.cls-6{fill:url(#Degradado_sin_nombre_2);}</style><clipPath id="clip-path"><rect class="cls-1" x="-557.21" y="90.87" width="500" height="290"/></clipPath><clipPath id="clip-path-2"><path class="cls-1" d="M9.87,7.5A1.53,1.53,0,0,0,8.33,9v7.53a1.57,1.57,0,0,0,.42,1.27l8.73,8.74a.19.19,0,0,0,.34-.13V17.53a.34.34,0,0,0-.34-.34H10.57a.81.81,0,0,1-.81-.81V9.74a.81.81,0,0,1,.81-.81H33.74a.81.81,0,0,1,.81.81v6.64a.81.81,0,0,1-.81.81H26.83a.34.34,0,0,0-.34.34V43.38a.82.82,0,0,1-.81.82H18.81a.22.22,0,0,0-.18.35l6.07,6.06a2.27,2.27,0,0,0,1.64.76h6.6a1.14,1.14,0,0,0,1.13-1.13V34.84a.34.34,0,0,1,.34-.34h.72C42.55,34.5,49.74,30,49.74,21c0-8-5.54-13.45-14.61-13.45Z"/></clipPath><linearGradient id="Degradado_sin_nombre_2" x1="-19.18" y1="-332.57" x2="-17.61" y2="-332.57" gradientTransform="matrix(26.39, 0, 0, -26.39, 514.58, -8748.52)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#743088"/><stop offset="0.21" stop-color="#743088"/><stop offset="0.34" stop-color="#7c2d87"/><stop offset="0.53" stop-color="#932383"/><stop offset="0.76" stop-color="#b8157d"/><stop offset="1" stop-color="#e30376"/></linearGradient></defs><polygon class="cls-4" points="63.06 25.75 57.59 25.75 57.59 21.39 73.13 21.39 73.13 25.75 67.66 25.75 67.66 44.2 63.06 44.2 63.06 25.75"/><path class="cls-4" d="M71.18,36.47c0-5,2.54-8.24,6.91-8.24s6.91,3.58,6.39,9.91H75.71c.36,2.28,1.73,3.19,4,3.19a10,10,0,0,0,3.84-.68v3.26a10.44,10.44,0,0,1-4.43.81c-5.12,0-7.92-3.13-7.92-8.25m9.29-1.23c0-2.22-.65-3.43-2.32-3.43s-2.44,1.31-2.6,3.43Z"/><rect class="cls-4" x="86.59" y="20.41" width="4.37" height="23.79"/><path class="cls-4" d="M92.9,36.38c0-5.05,2.54-8.25,6.91-8.25s6.91,3.59,6.39,9.91H97.43c.36,2.28,1.73,3.19,4,3.19a10,10,0,0,0,3.85-.68v3.26a10.44,10.44,0,0,1-4.43.81c-5.12,0-7.92-3.12-7.92-8.24m9.29-1.24c0-2.22-.65-3.42-2.32-3.42s-2.44,1.3-2.6,3.42Z"/><path class="cls-4" d="M116.18,44.62A4.67,4.67,0,0,1,112.53,43v8.34h-4.36V28.56h3.91v1.59a4.72,4.72,0,0,1,4-2c4.2,0,5.73,3.85,5.73,8.25,0,4.82-1.76,8.24-5.64,8.24m-3.65-5.41a2.69,2.69,0,0,0,2.38,1.6c1.86,0,2.58-1.6,2.58-4.43S116.77,32,114.91,32a2.68,2.68,0,0,0-2.38,1.59Z"/><path class="cls-4" d="M123.14,36.38c0-5.05,2.54-8.25,6.91-8.25S137,31.72,136.44,38h-8.77c.36,2.28,1.73,3.19,4,3.19a10,10,0,0,0,3.84-.68v3.26a10.44,10.44,0,0,1-4.43.81c-5.12,0-7.92-3.12-7.92-8.24m9.29-1.24c0-2.22-.65-3.42-2.31-3.42s-2.45,1.3-2.61,3.42Z"/><path class="cls-4" d="M138.19,28.56h3.91v2.37a4.72,4.72,0,0,1,5.05-2.7v4.43a4,4,0,0,0-4.59,2.67V44.2h-4.37Z"/><path class="cls-4" d="M150.79,32.34h-2.06V28.56h2.06V26.5c0-4.07,2.57-5.54,5.37-5.54a7,7,0,0,1,2.84.52v3.59a4.42,4.42,0,0,0-1.76-.33c-1.24,0-2.09.59-2.09,1.93v1.89h3.13v3.78h-3.13V44.2h-4.36Z"/><path class="cls-4" d="M172.92,36.38c0,5.15-2.5,8.24-7,8.24s-7-3.09-7-8.24,2.51-8.25,7-8.25,7,3.07,7,8.25m-9.64,0c0,2.87.75,4.43,2.64,4.43s2.6-1.56,2.6-4.43-.75-4.47-2.6-4.47-2.64,1.6-2.64,4.47"/><path class="cls-4" d="M174.5,28.56h3.91v2.37a4.71,4.71,0,0,1,5.05-2.7v4.43a4,4,0,0,0-4.6,2.67V44.2H174.5Z"/><path class="cls-4" d="M205.55,33.15V44.2h-4.3V34.06c0-1.27-.59-1.89-1.63-1.89a2.57,2.57,0,0,0-2.18,1.41V44.2h-4.27V34.06c0-1.27-.62-1.89-1.63-1.89a2.59,2.59,0,0,0-2.19,1.47V44.2h-4.3V28.56H189V30a4.83,4.83,0,0,1,3.94-1.89A4.2,4.2,0,0,1,196.62,30a5.25,5.25,0,0,1,4.24-1.89c3.16,0,4.69,2.22,4.69,5"/><path class="cls-4" d="M221.68,41.07v3.06a6.94,6.94,0,0,1-2.09.3,3.61,3.61,0,0,1-3-1.21,6.48,6.48,0,0,1-4.21,1.4c-2.67,0-5-1.56-5-4.85s2.45-5,5.94-5a12.1,12.1,0,0,1,2.51.29v-.85c0-1.27-.59-2.37-2.74-2.37a12.44,12.44,0,0,0-4.73,1V29a16,16,0,0,1,5.15-.91c4.34,0,6.58,1.92,6.58,6.39v5.57c0,.78.4,1,1,1a3.21,3.21,0,0,0,.53-.07m-5.8-.33v-3a6.76,6.76,0,0,0-1.57-.2c-1.6,0-2.67.52-2.67,2.09a1.8,1.8,0,0,0,1.89,2,3.24,3.24,0,0,0,2.35-.92"/><path class="cls-4" d="M223.29,28.56h3.91V30a5.24,5.24,0,0,1,4.24-1.89,4.64,4.64,0,0,1,4.72,5V44.2H231.8V34.06c0-1.33-.65-1.89-1.8-1.89a2.65,2.65,0,0,0-2.34,1.57V44.2h-4.37Z"/><path class="cls-4" d="M238,36.51c0-5.38,3.13-8.38,7.43-8.38a7.37,7.37,0,0,1,3.42.72v3.68a5.72,5.72,0,0,0-2.74-.65c-2.28,0-3.71,1.66-3.71,4.5s1.27,4.43,3.52,4.43a7.25,7.25,0,0,0,3.09-.65v3.78a8.76,8.76,0,0,1-3.78.68c-4.2,0-7.23-2.9-7.23-8.11"/><path class="cls-4" d="M250.16,36.38c0-5.05,2.55-8.25,6.91-8.25S264,31.72,263.46,38h-8.77c.36,2.28,1.73,3.19,4,3.19a10,10,0,0,0,3.85-.68v3.26a10.47,10.47,0,0,1-4.43.81c-5.12,0-7.93-3.12-7.93-8.24m9.29-1.24c0-2.22-.65-3.42-2.31-3.42s-2.45,1.3-2.61,3.42Z"/><path class="cls-4" d="M129.53,58.38a.26.26,0,0,1-.22.35,10,10,0,0,1-2.17.23c-2.29,0-3.42-1.07-3.42-3.63v-.22c0-2.25.87-3.62,3-3.62,2.69,0,2.78,2.18,2.84,3.42v.18c0,.21-.09.31-.3.31h-4.54c0,2,.77,2.84,2.56,2.84a10.55,10.55,0,0,0,2-.2c.23,0,.28.11.29.2Zm-4.84-3.6h4c0-1.66-.61-2.57-2-2.57s-2,.9-2,2.57"/><path class="cls-4" d="M135.45,55V53.6c0-.83-.41-1.39-1.74-1.39a13.12,13.12,0,0,0-2.06.22c-.23,0-.26,0-.31-.23l0-.11c-.05-.24,0-.31.25-.35a12.29,12.29,0,0,1,2.36-.25c1.82,0,2.44.72,2.44,2.11v4.63c0,.21,0,.28-.3.35a6.8,6.8,0,0,1-2.35.38c-1.68,0-3-.48-3-2.06v-.19c0-1.29,1-2,2.69-2a6.12,6.12,0,0,1,2,.34m-3.75,1.82c0,1,.75,1.45,2.07,1.45a4.82,4.82,0,0,0,1.68-.26V55.69a4.3,4.3,0,0,0-1.8-.35c-1.31,0-2,.5-2,1.42Z"/><path class="cls-4" d="M142.86,52.08c0,.19-.06.24-.23.23a8.89,8.89,0,0,0-1.36-.1c-1.64,0-2.33,1-2.33,2.91v.23c0,1.91.73,2.89,2.4,2.89a7.52,7.52,0,0,0,1.33-.11c.18,0,.21.15.22.25l0,.16c0,.19,0,.24-.23.27a9.33,9.33,0,0,1-1.52.15c-2.26,0-3.19-1.25-3.19-3.64v-.2c0-2.32,1-3.63,3.12-3.63a7.58,7.58,0,0,1,1.57.14c.16,0,.26.08.23.28Z"/><path class="cls-4" d="M150,58.53c0,.2-.09.29-.28.29h-.31c-.21,0-.3-.09-.3-.29V54.11c0-1.11-.51-1.86-1.74-1.86a5.76,5.76,0,0,0-2.06.44v5.84c0,.2-.1.29-.3.29h-.32c-.2,0-.29-.09-.29-.29V49c0-.2.09-.3.29-.3H145a.27.27,0,0,1,.3.3v2.93a7,7,0,0,1,2.3-.45c1.7,0,2.39,1,2.39,2.54Z"/><path class="cls-4" d="M155.22,50.44a.26.26,0,0,1-.3-.3v-.72c0-.21.09-.3.3-.3h.39c.19,0,.29.09.29.3v.72a.26.26,0,0,1-.29.3Zm1.9,7.9c0,.19,0,.29-.18.36a2.59,2.59,0,0,1-.73.12c-.79,0-1.25-.32-1.25-1.53V51.93c0-.21.09-.3.28-.3h.31c.21,0,.3.09.3.3v5.36c0,.56.17.81.57.81a1.93,1.93,0,0,0,.49-.08c.11,0,.17.06.19.22Z"/><path class="cls-4" d="M163.75,58.53c0,.2-.09.29-.28.29h-.31c-.21,0-.3-.09-.3-.29V54.11c0-1.11-.5-1.86-1.74-1.86a5.76,5.76,0,0,0-2.06.44v5.84c0,.2-.1.29-.3.29h-.31c-.21,0-.3-.09-.3-.29V52.48a.45.45,0,0,1,.28-.43,7.43,7.43,0,0,1,2.76-.56A2.26,2.26,0,0,1,163.75,54Z"/><path class="cls-4" d="M165.72,52.35h-.49c-.2,0-.29-.1-.29-.3v-.12c0-.21.09-.3.29-.3h.49V50.38a.27.27,0,0,1,.3-.3h.31a.26.26,0,0,1,.3.3v1.25h1.29c.22,0,.3.09.3.3v.12c0,.2-.08.3-.3.3h-1.29V57c0,.66.22,1.12.94,1.12a2.72,2.72,0,0,0,.69-.09c.12,0,.21.09.21.29v.13c0,.2,0,.26-.23.29a2.55,2.55,0,0,1-.77.1c-1.24,0-1.75-.62-1.75-1.84Z"/><path class="cls-4" d="M175,58.38a.26.26,0,0,1-.22.35,10.06,10.06,0,0,1-2.17.23c-2.3,0-3.43-1.07-3.43-3.63v-.22c0-2.25.87-3.62,3-3.62,2.69,0,2.79,2.18,2.84,3.42l0,.18c0,.21-.1.31-.3.31h-4.55c0,2,.77,2.84,2.57,2.84a10.33,10.33,0,0,0,2-.2c.23,0,.29.11.3.2Zm-4.85-3.6h4c0-1.66-.61-2.57-2-2.57s-2,.9-2,2.57"/><path class="cls-4" d="M180.45,52.05c0,.24-.07.28-.26.27a4.84,4.84,0,0,0-1-.08,4.42,4.42,0,0,0-1.55.28v6c0,.21-.09.3-.29.3H177c-.2,0-.29-.09-.29-.3V52.21a.33.33,0,0,1,.28-.35,5.3,5.3,0,0,1,2.11-.37,5.44,5.44,0,0,1,1.2.11c.22,0,.21.15.17.37Z"/><path class="cls-4" d="M185.79,55V53.6c0-.83-.41-1.39-1.74-1.39a13,13,0,0,0-2.06.22c-.24,0-.26,0-.32-.23l0-.11c-.06-.24,0-.31.24-.35a12.47,12.47,0,0,1,2.36-.25c1.82,0,2.45.72,2.45,2.11v4.63c0,.21,0,.28-.3.35a6.85,6.85,0,0,1-2.35.38c-1.68,0-3-.48-3-2.06v-.19c0-1.29,1-2,2.68-2a6.13,6.13,0,0,1,2,.34M182,56.86c0,1,.75,1.45,2.06,1.45a4.94,4.94,0,0,0,1.69-.26V55.69a4.32,4.32,0,0,0-1.81-.35c-1.3,0-1.94.5-1.94,1.42Z"/><path class="cls-4" d="M193.2,52.08c0,.19-.07.24-.23.23a8.89,8.89,0,0,0-1.36-.1c-1.64,0-2.33,1-2.33,2.91v.23c0,1.91.73,2.89,2.4,2.89a7.52,7.52,0,0,0,1.33-.11c.18,0,.2.15.22.25l0,.16c0,.19,0,.24-.23.27a9.23,9.23,0,0,1-1.52.15c-2.25,0-3.19-1.25-3.19-3.64v-.2c0-2.32,1-3.63,3.12-3.63a7.67,7.67,0,0,1,1.58.14c.16,0,.26.08.23.28Z"/><path class="cls-4" d="M194.87,52.35h-.49a.27.27,0,0,1-.3-.3v-.12a.26.26,0,0,1,.3-.3h.49V50.38a.26.26,0,0,1,.3-.3h.31a.27.27,0,0,1,.3.3v1.25h1.29c.22,0,.3.09.3.3v.12c0,.2-.08.3-.3.3h-1.29V57c0,.66.22,1.12.93,1.12a2.79,2.79,0,0,0,.7-.09c.12,0,.2.09.2.29v.13c0,.2,0,.26-.23.29a2.48,2.48,0,0,1-.76.1c-1.25,0-1.75-.62-1.75-1.84Z"/><path class="cls-4" d="M199,50.44a.27.27,0,0,1-.3-.3v-.72a.26.26,0,0,1,.3-.3h.4c.19,0,.28.09.28.3v.72c0,.2-.09.3-.28.3Zm1.9,7.9c0,.19,0,.29-.17.36a2.66,2.66,0,0,1-.74.12c-.79,0-1.25-.32-1.25-1.53V51.93c0-.21.1-.3.29-.3h.31c.21,0,.3.09.3.3v5.36c0,.56.16.81.57.81a2,2,0,0,0,.49-.08c.11,0,.16.06.19.22Z"/><path class="cls-4" d="M201.56,55.1c0-2.28,1.06-3.61,3.15-3.61s3.15,1.33,3.15,3.61v.24c0,2.26-1.06,3.62-3.15,3.62s-3.15-1.36-3.15-3.62Zm5.34,0c0-1.83-.72-2.89-2.19-2.89s-2.18,1.06-2.18,2.89v.24c0,1.79.7,2.9,2.18,2.9s2.19-1.11,2.19-2.9Z"/><path class="cls-4" d="M215.08,58.53c0,.2-.09.29-.28.29h-.31c-.21,0-.3-.09-.3-.29V54.11c0-1.11-.51-1.86-1.74-1.86a5.71,5.71,0,0,0-2.06.44v5.84c0,.2-.1.29-.3.29h-.32c-.2,0-.29-.09-.29-.29V52.48a.45.45,0,0,1,.28-.43,7.43,7.43,0,0,1,2.76-.56A2.26,2.26,0,0,1,215.08,54Z"/><path class="cls-4" d="M229.81,58.53c0,.2-.09.29-.3.29h-.31c-.2,0-.3-.09-.3-.29V54.21c0-1.17-.47-2-1.7-2a3.68,3.68,0,0,0-1.84.63v5.65c0,.2-.08.29-.29.29h-.32c-.21,0-.3-.09-.3-.29V53.41c0-.69-.48-1.16-1.71-1.16a4.76,4.76,0,0,0-1.82.44v5.84c0,.2-.1.29-.3.29h-.31c-.21,0-.3-.09-.3-.29V52.48a.38.38,0,0,1,.29-.4,6.07,6.07,0,0,1,2.52-.59,2.81,2.81,0,0,1,2.08.64,5.55,5.55,0,0,1,2.37-.64c1.79,0,2.54,1,2.54,2.65Z"/><path class="cls-4" d="M236,55V53.6c0-.83-.41-1.39-1.74-1.39a13.12,13.12,0,0,0-2.06.22c-.23,0-.26,0-.31-.23l0-.11c-.05-.24,0-.31.25-.35a12.29,12.29,0,0,1,2.36-.25c1.82,0,2.44.72,2.44,2.11v4.63c0,.21,0,.28-.3.35a6.8,6.8,0,0,1-2.35.38c-1.68,0-3-.48-3-2.06v-.19c0-1.29,1-2,2.69-2a6.12,6.12,0,0,1,2,.34m-3.74,1.82c0,1,.74,1.45,2.06,1.45a4.82,4.82,0,0,0,1.68-.26V55.69a4.3,4.3,0,0,0-1.8-.35c-1.31,0-1.94.5-1.94,1.42Z"/><path class="cls-4" d="M238.92,52.35h-.49a.27.27,0,0,1-.3-.3v-.12a.26.26,0,0,1,.3-.3h.49V50.38a.27.27,0,0,1,.3-.3h.31a.27.27,0,0,1,.3.3v1.25h1.29c.22,0,.3.09.3.3v.12c0,.2-.08.3-.3.3h-1.29V57c0,.66.22,1.12.94,1.12a2.77,2.77,0,0,0,.69-.09c.12,0,.2.09.2.29v.13c0,.2,0,.26-.23.29a2.48,2.48,0,0,1-.76.1c-1.25,0-1.75-.62-1.75-1.84Z"/><path class="cls-4" d="M242.88,52.35h-.49c-.2,0-.29-.1-.29-.3v-.12c0-.21.09-.3.29-.3h.49V50.38a.27.27,0,0,1,.3-.3h.31a.26.26,0,0,1,.3.3v1.25h1.29c.22,0,.3.09.3.3v.12c0,.2-.08.3-.3.3h-1.29V57c0,.66.22,1.12.94,1.12a2.72,2.72,0,0,0,.69-.09c.12,0,.21.09.21.29v.13c0,.2,0,.26-.24.29a2.42,2.42,0,0,1-.75.1c-1.25,0-1.76-.62-1.76-1.84Z"/><path class="cls-4" d="M252.2,58.38a.26.26,0,0,1-.22.35,10.06,10.06,0,0,1-2.17.23c-2.3,0-3.42-1.07-3.42-3.63v-.22c0-2.25.87-3.62,3-3.62,2.68,0,2.78,2.18,2.83,3.42l0,.18c0,.21-.1.31-.3.31h-4.55c0,2,.77,2.84,2.57,2.84a10.33,10.33,0,0,0,2-.2c.23,0,.29.11.3.2Zm-4.85-3.6h4c0-1.66-.61-2.57-2-2.57s-2,.9-2,2.57"/><path class="cls-4" d="M257.61,52.05c0,.24-.06.28-.26.27a4.84,4.84,0,0,0-1-.08,4.32,4.32,0,0,0-1.54.28v6a.26.26,0,0,1-.3.3h-.32c-.2,0-.29-.09-.29-.3V52.21a.33.33,0,0,1,.28-.35,5.3,5.3,0,0,1,2.11-.37,5.44,5.44,0,0,1,1.2.11c.22,0,.21.15.17.37Z"/><path class="cls-4" d="M263.27,52.1c0,.29-.09.3-.27.28a6.39,6.39,0,0,0-1.61-.17c-1.06,0-1.92.18-1.92,1,0,1.83,4.06.65,4.06,3.65,0,1.37-1,2.08-2.74,2.08a14.22,14.22,0,0,1-2.05-.19c-.24,0-.28-.08-.28-.33v-.09c0-.29.12-.33.32-.3a12.83,12.83,0,0,0,1.93.19c1.52,0,1.9-.6,1.9-1.33,0-2.42-4.05-1.13-4.05-3.68,0-1.45,1.22-1.74,2.68-1.74a7.53,7.53,0,0,1,1.83.17c.19,0,.26.08.23.34Z"/><g class="cls-5"><rect class="cls-6" x="8.33" y="7.5" width="41.42" height="43.87"/></g></svg> -->
                	<img src="../img/Teleperformance-60.jpg" alt="" style="width: 200px;">
				</div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="h-100 p-5 bg-light border rounded-3 border-3 border-end-0 border-bottom-0">
                <h3 class="text-center fw-bold">Transaction Information</h3>

                <div class="d-flex justify-content-between w-75 mx-auto">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="inlineCheckbox1" disabled>
                        <label class="form-check-label" for="inlineCheckbox1">CARD</label>
                    </div>
                    <div  class="my-auto line" id="line_1"></div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="inlineCheckbox2" value="option2" disabled>
                        <label class="form-check-label" for="inlineCheckbox2">DATE</label>
                    </div>
                    <div  class="my-auto line" id="line_2"></div>

                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="inlineCheckbox3" value="option2" disabled>
                        <label class="form-check-label" for="inlineCheckbox3">CVV</label>
                    </div>
                </div>

                <div id="content" class="mx-auto mt-4">
                    <div id="content-card" class="p-5">
                        <div id="bridge-message"><strong>Navigation:</strong></div>
                        <div id="bridge-result"><strong>Result:</strong></div>
                    </div>
                </div>

                <div class="d-flex justify-content-between w-75 mx-auto">
                    <button class="btn btn-outline-secondary w-25 mt-3 btn-lg text-light"
                            type="button" style="background-color: purple" id="btn-start-with-events" >Start</button>
                    <button class="btn btn-outline-secondary w-25 mt-3 btn-lg text-light"
                            type="button" style="background-color: purple" id="btn-cancel">
                            <span id="cancelsecurepay-text">Close</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

   <!-- <form class="col-sm-12 col-md-12 col-lg-12">-->
<!--        <h2>Pagamento Seguro Five9</h2>-->
<!--        <br>-->
<!--        <div class="form-group col-sm-6 col-md-6 col-lg-6">-->
<!--            <label for="firstName">Primeiro Nome</label>-->
<!--            <input type="text" class="form-control" id="firstName" placeholder="" value="Eduardo">-->
<!--        </div>-->
<!--        <div class="form-group col-sm-6 col-md-6 col-lg-6">-->
<!--            <label for="lastName">Sobrenome</label>-->
<!--            <input type="text" class="form-control" id="lastName" placeholder="" value="Viana">-->
<!--        </div>-->
<!--        <div class="form-group col-sm-6 col-md-6 col-lg-6">-->
<!--            <label for="invoiceNumber">Número da fatura</label>-->
<!--            <input type="text" class="form-control" id="invoiceNumber" placeholder="" value="12345">-->
<!--        </div>-->
<!--        <div class="form-group col-sm-6 col-md-6 col-lg-6">-->
<!--            <label for="paymentAmount">Valor da fatura</label>-->
<!--            <input type="text" class="form-control" id="paymentAmount" placeholder="" value="12.34">-->
<!--        </div>-->

<!--        &lt;!&ndash; Support for a Bridge Message &ndash;&gt;-->
<!--        <div id="bridge-message-div" class="form-group alert alert-success col-sm-12 col-md-12 col-lg-12" role="alert">-->
<!--            <div id="bridge-message"><strong>Navigation:</strong></div>-->
<!--            <div id="bridge-result"><strong>Result:</strong></div>-->
<!--        </div>-->

<!--        <div class="form-group col-sm-12 col-md-12 col-lg-12">-->
<!--            <button type="button" id="btn-start-with-events" class="btn btn-primary">-->
<!--                Iniciar-->
<!--            </button>-->
<!--            &lt;!&ndash; <button type="button" id="btn-start" class="btn btn-primary">-->
<!--                    Iniciar-->
<!--            </button> &ndash;&gt;-->
<!--            <button type="button" id="btn-cancel" class="btn btn-danger">-->
<!--            </button>-->
<!--        </div>-->

<!--    </form> -->

    <script>
        // Initialize the sample's UI
        $(document).ready(function() {
            uicontroller.init();

			// const valueURL = document.location.href
			// let url = new URL(valueURL)
			// let data = url.searchParams.get("call_id")

			// document.querySelector('#text').innerText = data
        });
    </script>
</body>
</html>
