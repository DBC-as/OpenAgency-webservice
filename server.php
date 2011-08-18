<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/** \brief
 *
 */


require_once('OLS_class_lib/webServiceServer_class.php');
require_once('OLS_class_lib/oci_class.php');

class openAgency extends webServiceServer {

    public function __construct() {
        webServiceServer::__construct('openagency.ini');
    }


    /** \brief
     *
     */
    function automation($param) {
        if (!$this->aaa->has_right('openagency', 500))
            $res->error->_value = 'authentication_error';
        else {
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                $agency = $this->strip_agency($param->agencyId->_value);
                switch ($param->autService->_value) {
                case 'autPotential':
                    try {
                        $oci->bind('bind_laantager', $agency);
                        $oci->bind('bind_materiale_id', $param->materialType->_value);
                        $oci->set_query('SELECT id_nr, valg
                                        FROM vip_fjernlaan
                                        WHERE laantager = :bind_laantager
                                        AND materiale_id = :bind_materiale_id');
                        $vf_row = $oci->fetch_into_assoc();
                    } catch (ociException $e) {
                        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                        $res->error->_value = 'service_unavailable';
                    }
                    if (empty($res->error))
                        if ($vf_row['VALG'] == 'a') {
                            try {
                                $oci->bind('bind_materiale_id', $param->materialType->_value);
                                $J = 'J';
                                $oci->bind('bind_status', $J);
                                $oci->set_query('SELECT laangiver
                                                FROM vip_fjernlaan
                                                WHERE materiale_id = :bind_materiale_id
                                                AND status = :bind_status');    // ??? NULL og DISTINCT
                                $ap = &$res->autPotential->_value;
                                $ap->materialType->_value = $param->materialType->_value;
                                while ($vf_row = $oci->fetch_into_assoc())
                                    if ($vf_row['LAANGIVER'])
                                        $ap->responder[]->_value = $vf_row['LAANGIVER'];
                            } catch (ociException $e) {
                                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                                $res->error->_value = 'service_unavailable';
                            }
                        } elseif ($vf_row['VALG'] == 'l') {
                        try {
                            $oci->bind('bind_fjernlaan_id', $vf_row['ID_NR']);
                            $oci->set_query('SELECT bib_nr
                                            FROM vip_fjernlaan_bibliotek
                                            WHERE fjernlaan_id = :bind_fjernlaan_id');
                            $ap = &$res->autPotential->_value;
                            $ap->materialType->_value = $param->materialType->_value;
                            while ($vfb_row = $oci->fetch_into_assoc())
                                $ap->responder[]->_value = $this->normalize_agency($vfb_row['BIB_NR']);
                        } catch (ociException $e) {
                            verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                            $res->error->_value = 'service_unavailable';
                        }
                    } else
                        $res->error->_value = 'no_agencies_found';
                    break;
                case 'autRequester':
                    try {
                        $oci->bind('bind_laantager', $agency);
                        $oci->bind('bind_materiale_id', $param->materialType->_value);
                        $oci->set_query('SELECT *
                                        FROM vip_fjernlaan
                                        WHERE laantager = :bind_laantager
                                        AND materiale_id = :bind_materiale_id');
                        $ar = &$res->autRequester->_value;
                        $ar->requester->_value = $agency;
                        $ar->materialType->_value = $param->materialType->_value;
                        if ($vf_row = $oci->fetch_into_assoc()) {
                            if ($vf_row['STATUS'] == 'T')
                                $ar->willSend->_value = 'TEST';
                            elseif ($vf_row['STATUS'] == 'J')
                            $ar->willSend->_value = 'YES';
                            else
                                $ar->willSend->_value = 'NO';
                            $ar->autPeriod->_value = $vf_row['PERIODE'];
                            $ar->autId->_value = $vf_row['ID_NR'];
                            $ar->autChoice->_value = $vf_row['VALG'];
                            $ar->autRes->_value = ($vf_row['RESERVERING'] == 'J' ? 'YES' : 'NO');
                        } else
                            $ar->willSend->_value = 'NO';
                    } catch (ociException $e) {
                        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                        $res->error->_value = 'service_unavailable';
                    }
                    break;
                case 'autProvider':
                    try {
                        $oci->bind('bind_laangiver', $agency);
                        $oci->bind('bind_materiale_id', $param->materialType->_value);
                        $oci->set_query('SELECT *
                                        FROM vip_fjernlaan
                                        WHERE laangiver = :bind_laangiver
                                        AND materiale_id = :bind_materiale_id');
                        $ap = &$res->autProvider->_value;
                        $ap->provider->_value = $agency;
                        $ap->materialType->_value = $param->materialType->_value;
                        if ($vf_row = $oci->fetch_into_assoc()) {
                            $ap->willReceive->_value = ($vf_row['STATUS'] == 'J' ? 'YES' : 'NO');
                            $ap->autPeriod->_value = $vf_row['PERIODE'];
                            $ap->autId->_value = $vf_row['ID_NR'];
                        } else
                            $ap->willReceive->_value = 'NO';
                    } catch (ociException $e) {
                        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                        $res->error->_value = 'service_unavailable';
                    }
                    break;
                default:
                    $res->error->_value = 'error_in_request';
                }
            }
        }
        //var_dump($res); var_dump($param); die();
        $ret->automationResponse->_value = $res;
        return $ret;
    }

    /** \brief
     *
     */
    public function encryption($param) {
        if (!$this->aaa->has_right('openagency', 500))
            $res->error->_value = 'authentication_error';
        else {
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                try {
                    $oci->bind('bind_email', $param->email->_value);
                    $oci->set_query('SELECT * FROM vip_krypt WHERE email = :bind_email');
                    while ($vk_row = $oci->fetch_into_assoc()) {
                        $o->encrypt->_value = 'YES';
                        $o->email->_value = $param->email->_value;
                        $o->agencyId->_value = $vk_row['BIBLIOTEK'];;
                        $o->key->_value = $vk_row['KEY'];
                        $o->base64->_value = ($vk_row['NOTBASE64'] == 'ja' ? 'NO' : 'YES');
                        $o->date->_value = $vk_row['UDL_DATO'];
                        $res->encryption[]->_value = $o;
                        unset($o);
                    }
                    if (empty($res))
                        $res->encryption[]->_value->encrypt->_value = 'NO';
                } catch (ociException $e) {
                    verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                    $res->error->_value = 'service_unavailable';
                }
            }
        }

        //var_dump($res); var_dump($param); die();
        $ret->encryptionResponse->_value = $res;
        return $ret;
    }
    /** \brief
     *
     */
    public function endUserOrderPolicy($param) {
        if (!$this->aaa->has_right('openagency', 500))
            $res->error->_value = 'authentication_error';
        else {
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                $assoc['cdrom']     = array('CDROM_BEST_MODT', 'CDROM_BEST_MODT_FJL');
                $assoc['journal']   = array('PER_BEST_MODT',   'PER_BEST_MODT_FJL');
                $assoc['monograph'] = array('MONO_BEST_MODT',  'MONO_BEST_MODT_FJL');
                $assoc['music']     = array('MUSIK_BEST_MODT', 'MUSIK_BEST_MODT_FJL');
                $assoc['newspaper'] = array('AVIS_BEST_MODT',  'AVIS_BEST_MODT_FJL');
                $assoc['video']     = array('VIDEO_BEST_MODT', 'VIDEO_BEST_MODT_FJL');
                if (strtolower($param->ownedByAgency->_value) == 'true'
                        || $param->ownedByAgency->_value == '1')
                    $owned_by_agency = 0;
                elseif (strtolower($param->ownedByAgency->_value) == 'false'
                        || $param->ownedByAgency->_value == '0')
                $owned_by_agency = 1;
                if (isset($owned_by_agency)
                        && ($will_receive = $assoc[strtolower($param->orderMaterialType->_value)][$owned_by_agency])) {
                    $agency = $this->strip_agency($param->agencyId->_value);
                    try {
                        $oci->bind('bind_bib_nr', $agency);
                        $oci->set_query('SELECT best_modt, ' . $will_receive . ' "WR" FROM vip_beh WHERE bib_nr = :bind_bib_nr');
                        if ($vb_row = $oci->fetch_into_assoc())
                            $res->willReceive->_value =
                                ($vb_row['BEST_MODT'] == 'J' && $vb_row['WR'] == 'J' ? 'true' : 'false');
                    } catch (ociException $e) {
                        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                        $res->error->_value = 'service_unavailable';
                    }
                } else
                    $res->error->_value = 'error_in_request';
            }
            if (empty($res))
                $res->error->_value = 'no_agencies_found';
        }

        //var_dump($res); var_dump($param); die();
        $ret->endUserOrderPolicyResponse->_value = $res;
        return $ret;
    }

    /** \brief
     *
     */
    public function service($param) { 
        if (!$this->aaa->has_right('openagency', 500))
            $res->error->_value = 'authentication_error';
        else {
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                $tab_col['v'] = array('bib_nr', 'navn', 'tlf_nr', 'fax_nr', 'email', 'badr', 'bpostnr', 'bcity', 'type', '*');
                $tab_col['vv'] = array('bib_nr', 'navn', 'tlf_nr', 'fax_nr', 'email', 'badr', 'bpostnr', 'bcity', 'bib_type', '*');
                $tab_col['vb'] = array('bib_nr', '*');
                $tab_col['vbst'] = array('bib_nr', 'ncip_address', '*');
                $tab_col['vd'] = array('bib_nr', 'svar_fax', 'svar_email', '*');
                $tab_col['vk'] = array('bib_nr', '*');
                $tab_col['oao'] = array('bib_nr', '*');
                foreach ($tab_col as $prefix => $arr)
                foreach ($arr as $col)
                $q .= (empty($q) ? '' : ', ') .
                      $prefix . '.' . $col .
                      ($col == '*' ? '' : ' "' . strtoupper($prefix . '.' . $col) . '"');
                $agency = $this->strip_agency($param->agencyId->_value);
                try {
                    $oci->bind('bind_bib_nr', $agency);
                    $oci->set_query('SELECT ' . $q . '
                                    FROM vip v, vip_vsn vv, vip_beh vb, vip_bestil vbst, vip_danbib vd, vip_kat vk, open_agency_ors oao
                                    WHERE v.bib_nr = vd.bib_nr (+)
                                    AND v.kmd_nr = vv.bib_nr (+)
                                    AND v.bib_nr = vk.bib_nr (+)
                                    AND v.bib_nr = vb.bib_nr (+)
                                    AND v.bib_nr = vbst.bib_nr (+)
                                    AND v.bib_nr = oao.bib_nr (+)
                                    AND v.bib_nr = :bind_bib_nr');
                    $oa_row = $oci->fetch_into_assoc();
                    $this->sanitize_array($oa_row);
                } catch (ociException $e) {
                    verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                    $res->error->_value = 'service_unavailable';
                }
                if (empty($oa_row))
                    $res->error->_value = 'agency_not_found';
                if (empty($res->error)) {
//        verbose::log(TRACE, 'OpenAgency('.__LINE__.'):: action=service&agencyId=' . $param->agencyId->_value .  '&service=' . $param->service->_value);
                    switch ($param->service->_value) {
                    case 'information':
                        $inf = &$res->information->_value;
                        $inf->agencyId->_value = $this->normalize_agency($oa_row['VV.BIB_NR']);
                        $inf->agencyName->_value = $oa_row['VV.NAVN'];
                        $inf->agencyPhone->_value = $oa_row['VV.TLF_NR'];
                        $inf->agencyFax->_value = $oa_row['VV.FAX_NR'];
                        $inf->agencyEmail->_value = $oa_row['VV.EMAIL'];
                        $inf->agencyType->_value = $oa_row['VV.BIB_TYPE'];
                        $inf->branchId->_value = $this->normalize_agency($oa_row['V.BIB_NR']);
                        $inf->branchName->_value = $oa_row['V.NAVN'];
                        $inf->branchPhone->_value = $oa_row['V.TLF_NR'];
                        $inf->branchFax->_value = $oa_row['VD.SVAR_FAX'];
                        $inf->branchEmail->_value = $oa_row['V.EMAIL'];
                        $inf->branchType->_value = $oa_row['V.TYPE'];
                        $inf->postalAddress->_value = $oa_row['V.BADR'];
                        $inf->postalCode->_value = $oa_row['V.BPOSTNR'];
                        $inf->city->_value = $oa_row['V.BCITY'];
                        $inf->isil->_value = $this->normalize_agency($oa_row['ISIL']);
                        $inf->junction->_value = $oa_row['KNUDEPUNKT'];
                        $inf->kvik->_value = ($oa_row['KVIK'] == 'kvik' ? 'YES' : 'NO');
                        $inf->lookupUrl->_value = $oa_row['URL_VIDERESTIL'];
                        $inf->norfri->_value = ($oa_row['NORFRI'] == 'norfri' ? 'YES' : 'NO');
                        $inf->requestOrder->_value = $oa_row['USE_LAANEVEJ'];
                        if (is_null($inf->sender->_value = $this->normalize_agency($oa_row['CHANGE_REQUESTER'])))
                            $inf->sender->_value = $this->normalize_agency($oa_row['V.BIB_NR']);
                        $inf->replyToEmail->_value = $oa_row['VD.SVAR_EMAIL'];
                        //print_r($oa_row); var_dump($res->information->_value); die();
                        break;
                    case 'orsAnswer':
                        $orsA = &$res->orsAnswer->_value;
                        $orsA->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
                        $orsA->willReceive->_value = (in_array($oa_row['ANSWER'], array('z3950', 'mail', 'ors')) ? 'YES' : '');
                        $orsA->synchronous->_value = 'false';
                        $orsA->protocol->_value = $oa_row['ANSWER'];
                        if ($oa_row['ANSWER'] == 'z3950')
                            $orsA->address->_value = $oa_row['ANSWER_Z3950_ADDRESS'];
                        elseif ($oa_row['ANSWER'] == 'mail')
                        $orsA->address->_value = $oa_row['ANSWER_MAIL_ADDRESS'];
                        $orsA->userId->_value = $oa_row['ANSWER_Z3950_USER'];
                        $orsA->groupId->_value = $oa_row['ANSWER_Z3950_GROUP'];
                        $orsA->passWord->_value = ($oa_row['ANSWER'] == 'z3950' ? $oa_row['ANSWER_Z3950_PASSWORD'] : $oa_row['ANSWER_NCIP_AUTH']);
                        //var_dump($res->orsAnswer->_value); die();
                        break;
                    case 'orsCancelRequestUser':
                        $orsCRU = &$res->orsCancelRequestUser->_value;
                        $orsCRU->responder->_value = $this->normalize_agency($oa_row['VK.BIB_NR']);
                        $orsCRU->willReceive->_value = ($oa_row['NCIP_CANCEL'] == 'J' ? 'YES' : 'NO');
                        $orsCRU->synchronous->_value = 'false';
                        $orsCRU->address->_value = $oa_row['NCIP_CANCEL_ADDRESS'];
                        $orsCRU->passWord->_value = $oa_row['NCIP_CANCEL_PASSWORD'];
                        //var_dump($res->orsCancelRequestUser->_value); die();
                        break;
                    case 'orsEndUserRequest':
                        $orsEUR = &$res->orsEndUserRequest->_value;
                        $orsEUR->responder->_value = $this->normalize_agency($oa_row['VB.BIB_NR']);
                        $orsEUR->willReceive->_value = ($oa_row['BEST_MODT'] == 'J' ? 'YES' : 'NO');
                        $orsEUR->synchronous->_value = 'false';
                        switch ($oa_row['BESTIL_VIA']) {
                        case 'A':
                            $orsEUR->protocol->_value = 'mail';
                            $orsEUR->address->_value = $oa_row['EMAIL_BESTIL'];
                            $orsEUR->format->_value = 'text';
                            break;
                        case 'B':
                            $orsEUR->protocol->_value = 'mail';
                            $orsEUR->address->_value = $oa_row['EMAIL_BESTIL'];
                            $orsEUR->format->_value = 'ill0';
                            break;
                        case 'C':
                            $orsEUR->protocol->_value = 'ors';
                            break;
                        case 'D':
                            $orsEUR->protocol->_value = 'ncip';
                            $orsEUR->address->_value = $oa_row['VBST.NCIP_ADDRESS'];
                            $orsEUR->passWord->_value = $oa_row['NCIP_PASSWORD'];
                            break;
                        }
                        //var_dump($res->orsEndUserRequest->_value); die();
                        break;
                    case 'orsEndUserIllRequest':
                        $orsEUIR = &$res->orsEndUserIllRequest->_value;
                        $orsEUIR->responder->_value = $this->normalize_agency($oa_row['VB.BIB_NR']);
                        $orsEUIR->willReceive->_value = ($oa_row['BEST_MODT'] == 'J' ? 'YES' : 'NO');
                        $orsEUIR->synchronous->_value = 'false';
                        switch ($oa_row['BESTIL_FJL_VIA']) {
                        case 'A':
                            $orsEUIR->protocol->_value = 'mail';
                            $orsEUIR->address->_value = $oa_row['EMAIL_FJL_BESTIL'];
                            $orsEUIR->format->_value = 'text';
                            break;
                        case 'B':
                            $orsEUIR->protocol->_value = 'mail';
                            $orsEUIR->address->_value = $oa_row['EMAIL_FJL_BESTIL'];
                            $orsEUIR->format->_value = 'ill0';
                            break;
                        case 'C':
                            $orsEUIR->protocol->_value = 'ors';
                            break;
                        }
                        break;
                    case 'orsItemRequest':
                        $orsIR = &$res->orsItemRequest->_value;
                        $orsIR->responder->_value = $this->normalize_agency($oa_row['VD.BIB_NR']);
                        switch ($oa_row['MAILBESTIL_VIA']) {
                        case 'A':
                            $orsIR->willReceive->_value = 'YES';
                            $orsIR->synchronous->_value = 'false';
                            $orsIR->protocol->_value = 'mail';
                            $orsIR->address->_value = $oa_row['BEST_EMAIL'];
                            break;
                        case 'B':
                            $orsIR->willReceive->_value = 'YES';
                            $orsIR->synchronous->_value = 'false';
                            $orsIR->protocol->_value = 'ors';
                            break;
                        case 'C':
                            $orsIR->willReceive->_value = 'YES';
                            $orsIR->synchronous->_value = 'false';
                            $orsIR->protocol->_value = 'z3950';
                            $orsIR->address->_value = $oa_row['URL_ITEMORDER_BESTIL'];
                            break;
                        case 'D':
                            $orsIR->willReceive->_value = 'NO';
                            $orsIR->synchronous->_value = 'false';
                            break;
                        default:
                            $orsIR->willReceive->_value = 'NO';
                            $orsIR->synchronous->_value = 'false';
                            break;
                        }
                        $orsIR->userId->_value = $oa_row['ZBESTIL_USERID'];
                        $orsIR->groupId->_value = $oa_row['ZBESTIL_GROUPID'];
                        $orsIR->passWord->_value = $oa_row['ZBESTIL_PASSW'];
                        if ($oa_row['MAILBESTIL_VIA'] == 'A')
                            switch ($oa_row['FORMAT_BEST']) {
                            case 'illdanbest':
                                $orsIR->format->_value = 'text';
                                break;
                            case 'ill0form':
                                $orsIR->format->_value = 'ill0';
                                break;
                            case 'ill5form':
                                $orsIR->format->_value = 'ill0';
                                break;
                            }
                        //var_dump($res->orsItemRequest->_value); die();
                        break;
                    case 'orsLookupUser':
                        $orsLU = &$res->orsLookupUser->_value;
                        $orsLU->responder->_value = $this->normalize_agency($oa_row['VK.BIB_NR']);
                        $orsLU->willReceive->_value = ($oa_row['NCIP_LOOKUP_USER'] == 'J' ? 'YES' : 'NO');
                        $orsLU->synchronous->_value = 'false';
                        $orsLU->address->_value = $oa_row['NCIP_LOOKUP_USER_ADDRESS'];
                        $orsLU->passWord->_value = $oa_row['NCIP_LOOKUP_USER_PASSWORD'];
                        //var_dump($res->orsLookupUser->_value); die();
                        break;
                    case 'orsReceipt':
                        $orsR = &$res->orsReceipt->_value;
                        $orsR->responder->_value = $this->normalize_agency($oa_row['VD.BIB_NR']);
                        $orsR->willReceive->_value = (in_array($oa_row['MAILKVITTER_VIA'], array('A', 'B')) ? 'YES' : 'NO');
                        $orsR->synchronous->_value = 'false';
                        if ($oa_row['MAILKVITTER_VIA'] == 'A') $orsR->protocol->_value = 'mail';
                        elseif ($oa_row['MAILKVITTER_VIA'] == 'B') $orsR->protocol->_value = 'ors';
                        else $orsR->protocol->_value = '';
                        $orsR->address->_value = $oa_row['KVIT_EMAIL'];
                        if ($oa_row['FORMAT_KVIT'] == 'ill0form') $orsR->format->_value = 'ill0';
                        elseif ($oa_row['FORMAT_KVIT'] == 'ill5form') $orsR->format->_value = 'ill0';
                        elseif ($oa_row['FORMAT_KVIT'] == 'illdanbest') $orsR->format->_value = 'text';
                        //var_dump($res->orsReceipt->_value); die();
                        break;
                    case 'orsRenew':
                        $orsR = &$res->orsRenew->_value;
                        $orsR->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
                        if ($oa_row['RENEW'] == 'z3950' || $oa_row['RENEW'] == 'ors') {
                            $orsR->willReceive->_value = 'YES';
                            $orsR->synchronous->_value = 'false';
                            $orsR->protocol->_value = $oa_row['RENEW'];
                            if ($oa_row['RENEW'] == 'z3950') {
                                $orsR->address->_value = $oa_row['RENEW_Z3950_ADDRESS'];
                                $orsR->userId->_value = $oa_row['RENEW_Z3950_USER'];
                                $orsR->groupId->_value = $oa_row['RENEW_Z3950_GROUP'];
                                $orsR->passWord->_value = $oa_row['RENEW_Z3950_PASSWORD'];
                            }
                        } else {
                            $orsR->willReceive->_value = 'NO';
                            $orsR->synchronous->_value = 'false';
                        }
                        //var_dump($res->orsRenew->_value); die();
                        break;
                    case 'orsRenewAnswer':
                        $orsRA = &$res->orsRenewAnswer->_value;
                        $orsRA->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
                        if ($oa_row['RENEWANSWER'] == 'z3950' || $oa_row['RENEWANSWER'] == 'ors') {
                            $orsRA->willReceive->_value = 'YES';
                            $orsRA->synchronous->_value = $oa_row['RENEW_ANSWER_SYNCHRONIC'] == 'J' ? 'true' : 'false';
                            $orsRA->protocol->_value = $oa_row['RENEWANSWER'];
                            if ($oa_row['RENEWANSWER'] == 'z3950') {
                                $orsRA->address->_value = $oa_row['RENEWANSWER_Z3950_ADDRESS'];
                                $orsRA->userId->_value = $oa_row['RENEWANSWER_Z3950_USER'];
                                $orsRA->groupId->_value = $oa_row['RENEWANSWER_Z3950_GROUP'];
                                $orsRA->passWord->_value = $oa_row['RENEWANSWER_Z3950_PASSWORD'];
                            }
                        } else {
                            $orsRA->willReceive->_value = 'NO';
                            $orsRA->synchronous->_value = 'false';
                        }
                        //var_dump($res->orsRenewAnswer->_value); die();
                        break;
                    case 'orsCancel':
                        $orsC = &$res->orsCancel->_value;
                        $orsC->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
                        if ($oa_row['CANCEL'] == 'z3950' || $oa_row['CANCEL'] == 'ors') {
                            $orsC->willReceive->_value = 'YES';
                            $orsC->synchronous->_value = 'false';
                            $orsC->protocol->_value = $oa_row['CANCEL'];
                            if ($oa_row['CANCEL'] == 'z3950') {
                                $orsC->address->_value = $oa_row['CANCEL_Z3950_ADDRESS'];
                                $orsC->userId->_value = $oa_row['CANCEL_Z3950_USER'];
                                $orsC->groupId->_value = $oa_row['CANCEL_Z3950_GROUP'];
                                $orsC->passWord->_value = $oa_row['CANCEL_Z3950_PASSWORD'];
                            }
                        } else {
                            $orsC->willReceive->_value = 'NO';
                            $orsC->synchronous->_value = 'false';
                        }
                        //var_dump($res->orsCancel->_value); die();
                        break;
                    case 'orsCancelReply':
                        $orsCR = &$res->orsRenewAnswer->_value;
                        $orsCR->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
                        if ($oa_row['CANCELREPLY'] == 'z3950' || $oa_row['CANCELREPLY'] == 'ors') {
                            $orsCR->willReceive->_value = 'YES';
                            $orsCR->synchronous->_value = $oa_row['CANCEL_ANSWER_SYNCHRONIC'] == 'J' ? 'true' : 'false';
                            $orsCR->protocol->_value = $oa_row['CANCELREPLY'];
                            if ($oa_row['CANCELREPLY'] == 'z3950') {
                                $orsCR->address->_value = $oa_row['CANCELREPLY_Z3950_ADDRESS'];
                                $orsCR->userId->_value = $oa_row['CANCELREPLY_Z3950_USER'];
                                $orsCR->groupId->_value = $oa_row['CANCELREPLY_Z3950_GROUP'];
                                $orsCR->passWord->_value = $oa_row['CANCELREPLY_Z3950_PASSWORD'];
                            }
                        } else {
                            $orsCR->willReceive->_value = 'NO';
                            $orsCR->synchronous->_value = 'false';
                        }
                        //var_dump($res->orsCancelReply->_value); die();
                        break;
                    case 'orsRenewItemUser':
                        $orsRIU = &$res->orsRenewItemUser->_value;
                        $orsRIU->responder->_value = $this->normalize_agency($oa_row['VK.BIB_NR']);
                        $orsRIU->willReceive->_value = ($oa_row['NCIP_RENEW'] == 'J' ? 'YES' : 'NO');
                        $orsRIU->synchronous->_value = 'false';
                        $orsRIU->address->_value = $oa_row['NCIP_RENEW_ADDRESS'];
                        $orsRIU->passWord->_value = $oa_row['NCIP_RENEW_PASSWORD'];
                        //var_dump($res->orsRenewItemUser->_value); die();
                        break;
                    case 'orsShipping':
                        $orsS = &$res->orsShipping->_value;
                        $orsS->responder->_value = $this->normalize_agency($oa_row['OAO.BIB_NR']);
                        $orsS->willReceive->_value = (in_array($oa_row['SHIPPING'], array('z3950', 'mail', 'ors')) ? 'YES' : '');
                        $orsS->synchronous->_value = 'false';
                        $orsS->protocol->_value = $oa_row['SHIPPING'];
                        $orsS->address->_value = '';
                        $orsS->userId->_value = $oa_row['SHIPPING_Z3950_USER'];
                        $orsS->groupId->_value = $oa_row['SHIPPING_Z3950_GROUP'];
                        $orsS->passWord->_value = ($oa_row['SHIPPING'] == 'z3950' ? $oa_row['SHIPPING_Z3950_PASSWORD'] : $oa_row['SHIPPING_NCIP_AUTH']);
                        if ($oa_row['SHIPPING'] == 'z3950')
                            $orsS->address->_value = $oa_row['SHIPPING_Z3950_ADDRESS'];
                        //var_dump($res->orsShipping->_value); die();
                        break;
                    case 'serverInformation':
                        $serI = &$res->serverInformation->_value;
                        $serI->responder->_value = $this->normalize_agency($oa_row['VD.BIB_NR']);
                        $serI->isil->_value = $this->normalize_agency($oa_row['ISIL']);
                        $serI->address->_value = $oa_row['URL_ITEMORDER_BESTIL'];
                        $serI->userId->_value = $oa_row['ZBESTIL_USERID'];
                        $serI->groupId->_value = $oa_row['ZBESTIL_GROUPID'];
                        $serI->passWord->_value = $oa_row['ZBESTIL_PASSW'];
                        //var_dump($res->serverInformation->_value); die();
                        break;
                    case 'userParameters':
                        $usrP = &$res->userParameters->_value;
                        $get_obl = array('LD_CPR' => 'cpr',
                                         'LD_ID' => 'common',
                                         'LD_LKST' => 'barcode',
                                         'LD_KLNR' => 'cardno',
                                         'LD_TXT' => 'optional');
                        $usrP->userIdType->_value = 'no_userid_selected';
                        foreach ($get_obl as $key => $val)
                        if (substr($oa_row[$key],0,1) == 'O') {
                            $usrP->userIdType->_value = $val;
                            break;
                        }
                        break;
                    default:
                        $res->error->_value = 'error_in_request';
                    }
                }
            }
        }


        //var_dump($res); var_dump($param); die();
        $ret->serviceResponse->_value = $res;
        return $ret;
    }

    /** \brief
     *
     */
    public function nameList($param) {
        if (!$this->aaa->has_right('openagency', 500))
            $res->error->_value = 'authentication_error';
        else {
            //var_dump($this->aaa->get_rights()); die();
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                if ($param->libraryType->_value == 'Folkebibliotek' ||
                        $param->libraryType->_value == 'Forskningsbibliotek') {
                    try {
                        $oci->bind('bind_bib_type', $param->libraryType->_value);
                        $u = 'U';
                        $oci->bind('bind_u', $u);
                        $oci->set_query('SELECT vsn.bib_nr, vsn.navn 
                                         FROM vip v, vip_vsn vsn 
                                         WHERE vsn.bib_type = :bind_bib_type 
                                           AND v.bib_nr = vsn.bib_nr 
                                           AND (v.delete_mark is null or v.delete_mark = :bind_u)');
                        while ($vv_row = $oci->fetch_into_assoc()) {
                            $o->agencyId->_value = $this->normalize_agency($vv_row['BIB_NR']);
                            $o->agencyName->_value = $vv_row['NAVN'];
                            $res->agency[]->_value = $o;
                            unset($o);
                        }
                    } catch (ociException $e) {
                        verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                        $res->error->_value = 'service_unavailable';
                    }
                } else
                    $res->error->_value = 'error_in_request';
            }
        }
        //var_dump($res); var_dump($param); die();
        $ret->nameListResponse->_value = $res;
        return $ret;
    }

    /** \brief
     *
     */
    public function openSearchProfile($param) {
        if (!$this->aaa->has_right('openagency', 500))
            $res->error->_value = 'authentication_error';
        else {
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                $agency = $this->strip_agency($param->agencyId->_value);
                $oci->bind('bind_agency', $agency);
                if ($profile = strtolower($param->profileName->_value)) {
                    $oci->bind('bind_profile', $profile);
                    $sql_add = ' AND lower(broendprofiler.name) = :bind_profile';
                }
                try {
                    $oci->set_query('SELECT DISTINCT broendprofiler.name bp_name, 
                                                     broendkilder.name, 
                                                     submitter, 
                                                     format 
                                     FROM broendkilder, broendprofil_kilder, broendprofiler
                                     WHERE broendkilder.id_nr = broendprofil_kilder.broendkilde_id
                                       AND broendprofil_kilder.profil_id = broendprofiler.id_nr
                                       AND broendprofiler.bib_nr = :bind_agency' . $sql_add);
                    while ($s_row = $oci->fetch_into_assoc()) {
                        $s->sourceName->_value = $s_row['NAME'];
                        $s->sourceOwner->_value = (strtolower($s_row['SUBMITTER']) == 'agency' ? $agency : $s_row['SUBMITTER']);
                        $s->sourceFormat->_value = $s_row['FORMAT'];
                        $res->profile[$s_row['BP_NAME']]->_value->profileName->_value = $s_row['BP_NAME'];
                        $res->profile[$s_row['BP_NAME']]->_value->source[]->_value = $s;
                        unset($s);
                    }
                } catch (ociException $e) {
                    verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                    $res->error->_value = 'service_unavailable';
                }
            }
        }
        //var_dump($res); var_dump($param); die();
        $ret->openSearchProfileResponse->_value = $res;
        return $ret;
    }


    /** \brief
     *
     */
    public function remoteAccess($param) {
        if (!$this->aaa->has_right('openagency', 550))
            $res->error->_value = 'authentication_error';
        else {
            $oci = new Oci($this->config->get_value('agency_credentials','setup'));
            $oci->set_charset('UTF8');
            try {
                $oci->connect();
            }
            catch (ociException $e) {
                verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI connect error: ' . $oci->get_error_string());
                $res->error->_value = 'service_unavailable';
            }
            if (empty($res->error)) {
                $agency = $this->strip_agency($param->agencyId->_value);
                try {
                    $oci->bind('bind_agency', $agency);
                    $uno = '1';
                    $oci->bind('bind_har_adgang', $uno);
                    $oci->set_query('SELECT fjernadgang_licenser.navn "licens_navn",
                                    fjernadgang_licenser.url "licens_url",
                                    fjernadgang_dbc.navn "dbc_navn",
                                    fjernadgang_dbc.url "dbc_url",
                                    fjernadgang_dbc.har_fjernadgang "dbc_har_fjernadgang",
                                    fjernadgang_andre.navn "andre_navn",
                                    fjernadgang_andre.url "andre_url",
                                    fjernadgang_andre.har_fjernadgang "andre_har_fjernadgang",
                                    fjernadgang.har_adgang,
                                    fjernadgang.faust,
                                    fjernadgang.url,
                                    autolink
                                    FROM fjernadgang, fjernadgang_licenser, fjernadgang_dbc, fjernadgang_andre, licensguide
                                    WHERE fjernadgang.bib_nr = :bind_agency
                                    AND fjernadgang.type = :bind_har_adgang
                                    AND fjernadgang.faust = fjernadgang_licenser.faust (+)
                                    AND fjernadgang.faust = fjernadgang_dbc.faust (+)
                                    AND fjernadgang.faust = fjernadgang_andre.faust (+)
                                    AND fjernadgang.bib_nr = licensguide.bib_nr (+)');
                    $buf = $oci->fetch_all_into_assoc();
//if ($_GET['debug']) die('<pre>' . print_r($buf, TRUE));
                    $res->agencyId->_value = $param->agencyId->_value;
                    foreach ($buf as $val) {
                        if ($s->name->_value = $val['licens_navn'])
                            if ($val['AUTOLINK'])
                                $s->url->_value = $val['AUTOLINK'];
                            else
                                $s->url->_value = ($val['URL'] ? $val['URL'] : $val['licens_url']);
                        elseif ($s->name->_value = $val['dbc_navn'])
                        $s->url->_value = ($val['URL'] ? $val['URL'] : $val['dbc_url']);
                        elseif ($s->name->_value = $val['andre_navn'])
                        $s->url->_value = ($val['URL'] ? $val['URL'] : $val['andre_url']);
                        if ($s->url->_value && $val['FAUST'] <> 1234567) {    // drop eBib
                            if ($val['URL'])
                                $s->url->_value = str_replace('[URL_FJERNADGANG]', $val['URL'], $s->url->_value);
                            else
                                $s->url->_value = str_replace('[URL_FJERNADGANG]', $val['licens_url'], $s->url->_value);
                            $s->url->_value = str_replace('[LICENS_ID]', $val['FAUST'], $s->url->_value);
                            $res->subscription[]->_value = $s;
                        }
                        unset($s);
                    }
                } catch (ociException $e) {
                    verbose::log(FATAL, 'OpenAgency('.__LINE__.'):: OCI select error: ' . $oci->get_error_string());
                    $res->error->_value = 'service_unavailable';
                }
            }
        }
        //var_dump($res); var_dump($param); die();
        $ret->remoteAccessResponse->_value = $res;
        return $ret;
    }


    /** \brief Echos config-settings
     *
     */
    public function show_info() {
        echo '<pre>';
        echo 'version             ' . $this->config->get_value('version', 'setup') . '<br/>';
        echo 'logfile             ' . $this->config->get_value('logfile', 'setup') . '<br/>';
        echo 'verbose             ' . $this->config->get_value('verbose', 'setup') . '<br/>';
        echo 'agency_credentials  ' . $this->strip_oci_pwd($this->config->get_value('agency_credentials', 'setup')) . '<br/>';
        echo 'aaa_credentials     ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
        echo '</pre>';
        die();
    }

    private function strip_oci_pwd($cred) {
        if (($p1 = strpos($cred, '/')) && ($p2 = strpos($cred, '@')))
            return substr($cred, 0, $p1) . '/********' . substr($cred, $p2);
        else
            return $cred;
    }


    /** \brief
     *  return libraryno - align to 6 digits
     */
    private function normalize_agency($id) {
        if (is_numeric($id))
            return sprintf('%06s', $id);
        else
            return $id;
    }

    /** \brief
     *  return only digits, so something like DK-710100 returns 710100
     */
    private function strip_agency($id) {
        return preg_replace('/\D/', '', $id);
    }

    /** \brief
     *  removes chr-10 and chr-13
     */
    private function sanitize_array(&$arr) {
        if (is_array($arr))
            foreach ($arr as $key => $val)
            if (is_scalar($val))
                $arr[$key] = str_replace("\r", ' ', str_replace("\n", ' ', $val));
    }

}

/**
 *   MAIN
 */

$ws=new openAgency();
$ws->handle_request();

?>
