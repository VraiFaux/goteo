<?php
namespace Goteo\Library {

    use Goteo\Model\Invest,
        Goteo\Model\Project,
        Goteo\Model\User,
        Goteo\Library\Feed,
        Goteo\Core\Redirection;

    // updated: 08/12/2014
    // OBSOLETO :: require_once __DIR__ . '/paypal/adaptivepayments.php';  // SDK paypal para operaciones API (minimizado)
    // Ahora usamos /vendor/paypal/* desde composer
    // Este archivo es el inyector de dependencias:
    //      - Instantiate 'service wrapper object' and 'request object'
    //      - Invoke appropiate method on the service object

    // configuración paypal : ruta al sdk_config.ini definido en seetings como \PP_CONFIG_PATH

    // namespace de \vendor\paypal\adaptivepayments-sdk-php\lib\PayPal\Service\AdaptivePaymentsService.php
    use \PayPal\Types\AP as PPAdaptivePayments;
    use \PayPal\Service as PPService;
    use \PayPal\Types\Common as PPTypes;




	/*
	 * Clase para usar los adaptive payments de paypal
	 */
    class Paypal {

        /**
         * @param object invest instancia del aporte: id, usuario, proyecto, cuenta, cantidad
         *
         * Método para crear un preapproval para un aporte
         * va a mandar al usuario a paypal para que confirme
         *
         * @TODO poner límite máximo de dias a lo que falte para los PRIMERA_RONDA/SEGUNDA_RONDA dias para evitar las cancelaciones
         */
        public static function preapproval($invest, &$errors = array()) {

			try {
                $project = Project::getMini($invest->project);

                    $returnURL = $invest->urlOK;
                    $cancelURL = $invest->urlNOK;

                    date_default_timezone_set('UTC');
                    $currDate = getdate();
                    $hoy = $currDate['year'].'-'.$currDate['mon'].'-'.$currDate['mday'];
                    $startDate = strtotime($hoy);
                    $startDate = date('Y-m-d', mktime(date('h',$startDate),date('i',$startDate),0,date('m',$startDate),date('d',$startDate),date('Y',$startDate)));
                    $endDate = strtotime($hoy);
                    $endDate = date('Y-m-d', mktime(0,0,0,date('m',$endDate)+5,date('d',$endDate),date('Y',$endDate)));
                    // sí, pongo la fecha de caducidad de los preapprovals a 5 meses para tratar incidencias


                    // más valores para el preapproval request
                    $memo = "Aporte de {$invest->amount} EUR al proyecto: {$project->name}";
                    $customerId = $invest->user->id;
                    $totalAmount = $invest->amount;

		           /* Make the call to PayPal to get the preapproval token
		            If the API call succeded, then redirect the buyer to PayPal
		            to begin to authorize payment.  If an error occured, show the
		            resulting errors
		            */

                /// @TODO : pasar a src/Goteo/Library/paypal.php como wraper para vendor/paypal

		           $preapprovalRequest = new PPAdaptivePayments\preapprovalRequest;
                   $preapprovalRequest->memo = $memo;
		           $preapprovalRequest->cancelUrl = $cancelURL;
		           $preapprovalRequest->returnUrl = $returnURL;
		           $preapprovalRequest->clientDetails = new PPTypes\ClientDetailsType;
		           $preapprovalRequest->clientDetails->customerId = $customerId;
		           $preapprovalRequest->clientDetails->applicationId = PAYPAL_APPLICATION_ID;
		           $preapprovalRequest->clientDetails->deviceId = PAYPAL_DEVICE_ID;
		           $preapprovalRequest->clientDetails->ipAddress = $_SERVER['REMOTE_ADDR'];
		           $preapprovalRequest->currencyCode = "EUR";
		           $preapprovalRequest->startingDate = $startDate;
		           $preapprovalRequest->endingDate = $endDate;
		           $preapprovalRequest->maxNumberOfPayments = 1;
		           $preapprovalRequest->displayMaxTotalAmount = true;
		           $preapprovalRequest->feesPayer = 'EACHRECEIVER';
		           $preapprovalRequest->maxTotalAmountOfAllPayments = $totalAmount;
		           $preapprovalRequest->requestEnvelope = new PPTypes\RequestEnvelope;
		           $preapprovalRequest->requestEnvelope->errorLanguage = "es_ES";

		           $ap = new PPService\AdaptivePaymentsService;
		           $response=$ap->Preapproval($preapprovalRequest);

                // @todo : cambiar a catch Exception o return;
                if(strtoupper($ap->isSuccess) == 'FAILURE') {

                        Invest::setDetail($invest->id, 'paypal-conection-fail', 'Ha fallado la comunicacion con paypal al iniciar el preapproval. Proceso libary/paypal::preapproval');
                       $errors[] = 'No se ha podido iniciar la comunicación con paypal para procesar la preaprovación del cargo. ' . $ap->getLastError();
                        Feed::logger('paypal-communication-error', 'invest', $invest->id, 'ERROR en ' . __FUNCTION__ . ' ap->success = FAILURE.<br /><pre>' . print_r($response, true) . '</pre>', '\Library\Paypal:'.__FUNCTION__);

                        return false;


					}

                    // Guardar el codigo de preaproval en el registro de aporte y mandarlo a paypal
                    $token = $response->preapprovalKey;

                // @todo : cambiar a catch Exception o return;
                    if (!empty($token)) {


                        Invest::setDetail($invest->id, 'paypal-init', 'Se ha iniciado el preaproval y se redirije al usuario a paypal para aceptarlo. Proceso libary/paypal::preapproval');
                        $invest->setPreapproval($token);
                        $payPalURL = PAYPAL_REDIRECT_URL.'_ap-preapproval&preapprovalkey='.$token;
                        throw new Redirection($payPalURL, Redirection::TEMPORARY);
                        return true;



                    } else {


                        Invest::setDetail($invest->id, 'paypal-init-fail', 'Ha fallado al iniciar el preapproval y no se redirije al usuario a paypal. Proceso libary/paypal::preapproval');
                        $errors[] = 'No preapproval key obtained. <pre>' . print_r($response, true) . '</pre>';
                        Feed::logger('paypal-communication-error', 'invest', $invest->id, 'ERROR. No preapproval key obtained.<br /><pre>' . print_r($response, true) . '</pre>', '\Library\Paypal:'.__FUNCTION__);

                        return false;


                    }

			}
			catch(Exception $ex) {

                Invest::setDetail($invest->id, 'paypal-init-fail', 'Ha fallado al iniciar el preapproval y no se redirije al usuario a paypal. Proceso libary/paypal::preapproval');
                $errors[] = 'Error fatal en la comunicación con Paypal, se ha reportado la incidencia. Disculpe las molestias.';
                Feed::logger('paypal-exception', 'invest', $invest->id, $ex->getMessage(), '\Library\Paypal.php');

                return false;


			}

        }


        /*
         *  Metodo para ejecutar pago (desde cron)
         * Recibe parametro del aporte (id, cuenta, cantidad)
         *
         * Es un pago encadenado, la comision del 8% a Goteo y el resto al proyecto
         *
         */
        public static function pay($invest, &$errors = array()) {

            if ($invest->status == 1) {
                $errors[] = 'Este aporte ya está cobrado!';
                Feed::logger('paypal-dobleexecution', 'invest', $invest->id, 'Dobleejecución de preapproval. Se intentaba ejecutar un aporte en estado Cobrado. <br /><pre>' . print_r($invest, true) . '</pre>');

                return false;
            }

            try {
                $project = Project::getMini($invest->project);
                $userData = User::getMini($invest->user);

                // al productor le pasamos el importe del cargo menos el 8% que se queda goteo
                $amountPay = $invest->amount - ($invest->amount * 0.08);


                /// @TODO : pasar a src/Goteo/Library/paypal.php como wraper para vendor/paypal

                // Create request object
                $payRequest = new PPAdaptivePayments\PayRequest;
                $payRequest->memo = "Cargo del aporte de {$invest->amount} EUR del usuario '{$userData->name}' al proyecto '{$project->name}'";
                $payRequest->cancelUrl = SEC_URL.'/invest/charge/fail/' . $invest->id;
                $payRequest->returnUrl = SEC_URL.'/invest/charge/success/' . $invest->id;
                $payRequest->clientDetails = new PPTypes\ClientDetailsType;
		        $payRequest->clientDetails->customerId = $invest->user;
                $payRequest->clientDetails->applicationId = PAYPAL_APPLICATION_ID;
                $payRequest->clientDetails->deviceId = PAYPAL_DEVICE_ID;
                $payRequest->clientDetails->ipAddress = PAYPAL_IP_ADDRESS;
                $payRequest->currencyCode = 'EUR';
           		$payRequest->preapprovalKey = $invest->preapproval;
                $payRequest->actionType = 'PAY_PRIMARY';
                $payRequest->feesPayer = 'EACHRECEIVER';
                $payRequest->reverseAllParallelPaymentsOnError = true;
//                $payRequest->trackingId = $invest->id;
                // SENDER no vale para chained payments   (PRIMARYRECEIVER, EACHRECEIVER, SECONDARYONLY)
                $payRequest->requestEnvelope = new PPTypes\RequestEnvelope;
                $payRequest->requestEnvelope->errorLanguage = 'es_ES';

                // Primary receiver, Goteo Business Account
                $receiverP = new PPAdaptivePayments\Receiver;
                $receiverP->email = PAYPAL_BUSINESS_ACCOUNT; // tocar en config para poner en real
                $receiverP->amount = $invest->amount;
                $receiverP->primary = true;

                // Receiver, Projects PayPal Account
                $receiver = new PPAdaptivePayments\Receiver;
//              en dev/beta la cuenta paypal del proyecto es la de sandbox
                $receiver->email = (GOTEO_ENV == 'real') ? \trim($invest->account) : 'projec_1314918267_per@gmail.com';
                $receiver->amount = $amountPay;
                $receiver->primary = false;

                $payRequest->receiverList = array($receiverP, $receiver);

                // Create service wrapper object
                $ap = new PPService\AdaptivePaymentsService;

                // invoke business method on service wrapper passing in appropriate request params
                $response = $ap->Pay($payRequest);

                // Check response
                // @todo : cambiar a catch Exception o return;

                if(strtoupper($ap->isSuccess) == 'FAILURE') {
                    $error_txt = '';
                    $soapFault = $ap->getLastError();
                    if(is_array($soapFault->error)) {
                        $errorId = $soapFault->error[0]->errorId;
                        $errorMsg = $soapFault->error[0]->message;
                    } else {
                        $errorId = $soapFault->error->errorId;
                        $errorMsg = $soapFault->error->message;
                    }
                    if (is_array($soapFault->payErrorList->payError)) {
                        $errorId = $soapFault->payErrorList->payError[0]->error->errorId;
                        $errorMsg = $soapFault->payErrorList->payError[0]->error->message;
                    }

                    // tratamiento de errores
                    switch ($errorId) {
                        case '569013': // preapproval cancelado por el usuario desde panel paypal
                        case '539012': // preapproval no se llegó a autorizar
                            if ($invest->cancel()) {
                                $action = 'Aporte cancelado';

                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Aporte cancelado por preaproval cancelado por el usuario paypal', '/admin/accounts',
                                    \vsprintf('Se ha <span class="red">Cancelado</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s por preapproval cancelado', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            }
                            break;
                        case '569042': // cuenta del proyecto no confirmada en paypal
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Cuenta del proyecto no confirmada en PayPal', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque la cuenta del proyecto <span class="red">no está confirmada</span> en PayPal', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        case '580022': // uno de los mails enviados no es valido
                        case '589039': // el mail del preaproval no está registrada en paypal
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('El mail del preaproval no esta registrado en PayPal', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque el mail del preaproval <span class="red">no está registrado</span> en PayPal', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        case '520009': // la cuenta esta restringida por paypal
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('La cuenta esta restringida por PayPal', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque la cuenta <span class="red">está restringida</span> por PayPal', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        case '579033': // misma cuenta que el proyecto
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Se ha usado la misma cuenta que del proyecto', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque la cuenta <span class="red">es la misma</span> que la del proyecto', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        case '579024': // fuera de fechas
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Está fuera del rango de fechas', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque estamos <span class="red">fuera del rango de fechas</span> del preapproval', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        case '579031': // The total amount of all payments exceeds the maximum total amount for all payments
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Problema con los importes', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque ha habido <span class="red">algun problema con los importes</span>', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        case '520002': // Internal error
                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Error interno de PayPal', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s porque ha habido <span class="red">un error interno en PayPal</span>', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);

                            break;
                        default:
                            if (empty($errorId)) {
                                Feed::logger('paypal-error', 'invest', $invest->id, 'No tenemos ErrorId.<br /><pre>' . print_r($response, true) . '</pre>', '\Library\Paypal::pay()');

                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Error interno de PayPal', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s <span class="red">NO es soapFault pero no es Success</span>, se ha reportado el error.', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);
                            } else {
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Error interno de PayPal', '/admin/accounts',
                                    \vsprintf('Ha <span class="red">fallado al ejecutar</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s <span class="red">Payment '.$errorMsg.' ['.$errorId.']</span>', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                $error_txt = $log->title;
                                unset($log);
                            }
                            break;
                    }


                    if (empty($errorId)) {
                        $errors[] = 'NO es soapFault pero no es Success: <pre>' . print_r($ap, true) . '</pre>';
                    } elseif (!empty($error_txt)) {
                        $errors[$errorId] = $error_txt;
                    } else {
                        $errors[$errorId] = "$action $errorMsg [$errorId]";
                    }

                    Invest::setIssue($invest->id);
                    return false;
                }

                $token = $response->payKey;

                // @todo : cambiar a catch Exception o return;
                if (!empty($token)) {
                    if ($invest->setPayment($token)) {

                        if ($response->paymentExecStatus != 'INCOMPLETE') {
                            Invest::setIssue($invest->id);
                            $errors[] = "Error de Fuente de crédito.";
                            return false;
                        }
                        $invest->setStatus(1);
                        return true;

                    } else {

                        Invest::setIssue($invest->id);
                        $errors[] = "Obtenido payKey: $token pero no se ha grabado correctamente (paypal::setPayment) en el registro id: {$invest->id}.";
                        Feed::logger('paypal-error', 'invest', $invest->id, 'Metodo Invest->setPayment() ha fallado.<br /><pre>' . print_r($response, true) . '</pre>');

                        return false;

                    }
                } else {

                    Invest::setIssue($invest->id);
                    $errors[] = 'No ha obtenido Payment Key.';
                    Feed::logger('paypal-token', 'invest', $invest->id, ' No payment key obtained.<br /><pre>' . print_r($response, true) . '</pre>');

                    return false;

                }

            }
            catch (Exception $ex) {

                Invest::setIssue($invest->id);
                $errors[] = 'No se ha podido inicializar la comunicación con Paypal, se ha reportado la incidencia.';
                Feed::logger('paypal-exception', 'invest', $invest->id, $ex->getMessage(), '\Library\Paypal.php:'.__FUNCTION__);

                return false;
            }

        }


        /*
         *  Metodo para ejecutar pago secundario (desde cron/dopay)
         * Recibe parametro del aporte (id, cuenta, cantidad)
         */
        public static function doPay($invest, &$errors = array()) {

            try {
                $project = Project::getMini($invest->project);
                $userData = User::getMini($invest->user);

                // Create request object
                $payRequest = new PPAdaptivePayments\ExecutePaymentRequest;
                $payRequest->payKey = $invest->payment;
                $payRequest->requestEnvelope = 'SOAP';

                // Create service wrapper object
                $ap = new PPService\AdaptivePaymentsService;

                // invoke business method on service wrapper passing in appropriate request params
                $response = $ap->ExecutePayment($payRequest);

                // Check response
                if(strtoupper($ap->isSuccess) == 'FAILURE') {
                    $soapFault = $ap->getLastError();
                    if(is_array($soapFault->error)) {
                        $errorId = $soapFault->error[0]->errorId;
                        $errorMsg = $soapFault->error[0]->message;
                    } else {
                        $errorId = $soapFault->error->errorId;
                        $errorMsg = $soapFault->error->message;
                    }
                    if (is_array($soapFault->payErrorList->payError)) {
                        $errorId = $soapFault->payErrorList->payError[0]->error->errorId;
                        $errorMsg = $soapFault->payErrorList->payError[0]->error->message;
                    }

                    // tratamiento de errores
                    switch ($errorId) {
                        case '569013': // preapproval cancelado por el usuario desde panel paypal
                        case '539012': // preapproval no se llegó a autorizar
                            if ($invest->cancel()) {
                                $action = 'Aporte cancelado';

                                // Evento Feed
                                $log = new Feed();
                                $log->setTarget($project->id);
                                $log->populate('Aporte cancelado por preaproval cancelado por el usuario paypal', '/admin/invests',
                                    \vsprintf('Se ha <span class="red">Cancelado</span> el aporte de %s de %s (id: %s) al proyecto %s del dia %s por preapproval cancelado', array(
                                        Feed::item('user', $userData->name, $userData->id),
                                        Feed::item('money', $invest->amount.' &euro;'),
                                        Feed::item('system', $invest->id),
                                        Feed::item('project', $project->name, $project->id),
                                        Feed::item('system', date('d/m/Y', strtotime($invest->invested)))
                                )));
                                $log->doAdmin('system');
                                unset($log);

                            }
                            break;
                    }


                    if (empty($errorId)) {
                        $errors[] = 'NO es soapFault pero no es Success: <pre>' . print_r($ap, true) . '</pre>';
                        Feed::logger('paypal-exception', 'invest', $invest->id, ' No es un soap fault pero no es un success.<br /><pre>' . print_r($ap, true) . '</pre>', '\Library\Paypal::'.__FUNCTION__);

                    } else {
                        $errors[] = "$action $errorMsg [$errorId]";
                    }

                    return false;
                }

                // verificar el campo paymentExecStatus
                if ($response->paymentExecStatus == 'COMPLETED') {
                    if ($invest->setStatus('3')) {
                        return true;
                    } else {
                        $errors[] = "Obtenido estatus de ejecución {$response->paymentExecStatus} pero no se ha actualizado el registro de aporte id {$invest->id}.";
                        Feed::logger('paypal-error', 'invest', $invest->id, 'Obtenido estatus de ejecución '.$response->paymentExecStatus.' pero no se ha actualizado el registro de aporte<br /><pre>' . print_r($response, true) . '</pre>', '\Library\Paypal::'.__FUNCTION__);

                        return false;
                    }
                } else {
                    $errors[] = 'No se ha completado el pago encadenado, no se ha pagado al proyecto.';
                    Feed::logger('paypal-error', 'invest', $invest->id, 'Obtenido estatus de ejecución '.$response->paymentExecStatus.'. No payment exec status completed.<br /><pre>' . print_r($response, true) . '</pre>', '\Library\Paypal::'.__FUNCTION__);

                    return false;
                }

            }
            catch (Exception $ex) {

                $errors[] = 'No se ha podido inicializar la comunicación con Paypal, se ha reportado la incidencia.';
                Feed::logger('paypal-exception', 'invest', $invest->id, $ex->getMessage(), '\Library\Paypal.php:'.__FUNCTION__);

                return false;
            }

        }


        /*
         * Llamada a paypal para obtener los detalles de un preapproval
         */
        public static function preapprovalDetails ($key, &$errors = array()) {
            try {
                $PDRequest = new PPAdaptivePayments\PreapprovalDetailsRequest;

                $PDRequest->requestEnvelope = new PPTypes\RequestEnvelope;
                $PDRequest->requestEnvelope->errorLanguage = "es_ES";
                $PDRequest->preapprovalKey = $key;

                $ap = new PPService\AdaptivePaymentsService;
                $response = $ap->PreapprovalDetails($PDRequest);

                if(strtoupper($ap->isSuccess) == 'FAILURE') {
                    $errors[] = 'No preapproval details obtained. <pre>' . print_r($ap->getLastError(), true) . '</pre>';
                    return false;
                } else {
                    return $response;
                }
            }
            catch(Exception $ex) {

                $errors[] = 'No se ha podido inicializar la comunicación con Paypal, se ha reportado la incidencia.';
                Feed::logger('paypal-exception', 'invest', $invest->id, $ex->getMessage(), '\Library\Paypal.php:'.__FUNCTION__);

                return false;
            }
        }

        /*
         * Llamada a paypal para obtener los detalles de un cargo
         */
        public static function paymentDetails ($key, &$errors = array()) {
            try {
                $pdRequest = new PPAdaptivePayments\PaymentDetailsRequest;
                $pdRequest->payKey = $key;
                $rEnvelope = new PPTypes\RequestEnvelope;
                $rEnvelope->errorLanguage = "es_ES";
                $pdRequest->requestEnvelope = $rEnvelope;

                $ap = new PPService\AdaptivePaymentsService;
                $response=$ap->PaymentDetails($pdRequest);

                if(strtoupper($ap->isSuccess) == 'FAILURE') {
                    $errors[] = 'No payment details obtained. <pre>' . print_r($ap->getLastError(), true) . '</pre>';
                    return false;
                } else {
                    return $response;
                }
            }
            catch(Exception $ex) {

                $errors[] = 'No se ha podido inicializar la comunicación con Paypal, se ha reportado la incidencia.';
                Feed::logger('paypal-exception', 'invest', $invest->id, $ex->getMessage(), '\Library\Paypal.php:'.__FUNCTION__);

                return false;
            }
        }


        /*
         * Llamada para cancelar un preapproval (si llega a los PRIMERA_RONDA sin conseguir el mínimo)
         * recibe la instancia del aporte
         */
        public static function cancelPreapproval ($invest, &$errors = array(), $fail = false) {
            try {
                if (empty($invest->preapproval)) {
                    $invest->cancel($fail);
                    return true;
                }

                $CPRequest = new PPAdaptivePayments\CancelPreapprovalRequest;

                $CPRequest->requestEnvelope = new PPTypes\RequestEnvelope;
                $CPRequest->requestEnvelope->errorLanguage = "es_ES";
                $CPRequest->preapprovalKey = $invest->preapproval;

                $ap = new PPService\AdaptivePaymentsService;
                $response = $ap->CancelPreapproval($CPRequest);


                if(strtoupper($ap->isSuccess) == 'FAILURE') {
                    Invest::setDetail($invest->id, 'paypal-cancel-fail', 'Ha fallado al cancelar el preapproval. Proceso libary/paypal::cancelPreapproval');
                    $errors[] = 'Preapproval cancel failed.' . $ap->getLastError();
                    Feed::logger('paypal-error', 'invest', $invest->id, $implode('<br />', $errors), 'libary/paypal::cancelPreapproval');

                    return false;
                } else {
                    Invest::setDetail($invest->id, 'paypal-cancel', 'El Preapproval se ha cancelado y con ello el aporte. Proceso libary/paypal::cancelPreapproval');
                    $invest->cancel($fail);
                    return true;
                }
            }
            catch(Exception $ex) {


                Invest::setDetail($invest->id, 'paypal-cancel-fail', 'Ha fallado al cancelar el preapproval. Proceso libary/paypal::cancelPreapproval');
                $errors[] = 'No se ha podido inicializar la comunicación con Paypal, se ha reportado la incidencia.';
                Feed::logger('paypal-exception', 'invest', $invest->id, $ex->getMessage(), '\Library\Paypal.php:'.__FUNCTION__);

                return false;
            }
        }



	}

}
