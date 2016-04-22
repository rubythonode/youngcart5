<?php
include_once('./_common.php');
include_once(G5_SHOP_PATH.'/settle_inicis.inc.php');
require_once(G5_SHOP_PATH.'/inicis/libs/HttpClient.php');
require_once(G5_SHOP_PATH.'/inicis/libs/json_lib.php');

try {

    //#############################
    // 인증결과 파라미터 일괄 수신
    //#############################
    //      $var = $_REQUEST["data"];

    //#####################
    // 인증이 성공일 경우만
    //#####################
    if (strcmp('0000', $_REQUEST['resultCode']) == 0) {

        //############################################
        // 1.전문 필드 값 설정(***가맹점 개발수정***)
        //############################################

        $charset = 'UTF-8';        // 리턴형식[UTF-8,EUC-KR](가맹점 수정후 고정)

        $format = 'JSON';        // 리턴형식[XML,JSON,NVP](가맹점 수정후 고정)
        // 추가적 noti가 필요한 경우(필수아님, 공백일 경우 미발송, 승인은 성공시, 실패시 모두 Noti발송됨) 미사용
        //String notiUrl    = "";

        $authToken = $_REQUEST['authToken'];   // 취소 요청 tid에 따라서 유동적(가맹점 수정후 고정)

        $authUrl = $_REQUEST['authUrl'];    // 승인요청 API url(수신 받은 값으로 설정, 임의 세팅 금지)

        $netCancel = $_REQUEST['netCancel'];   // 망취소 API url(수신 받은f값으로 설정, 임의 세팅 금지)

        ///$mKey = $util->makeHash(signKey, "sha256"); // 가맹점 확인을 위한 signKey를 해시값으로 변경 (SHA-256방식 사용)
        $mKey = hash("sha256", $signKey);

        //#####################
        // 2.signature 생성
        //#####################
        $signParam['authToken'] = $authToken;  // 필수
        $signParam['timestamp'] = $timestamp;  // 필수
        // signature 데이터 생성 (모듈에서 자동으로 signParam을 알파벳 순으로 정렬후 NVP 방식으로 나열해 hash)
        $signature = $util->makeSignature($signParam);


        //#####################
        // 3.API 요청 전문 생성
        //#####################
        $authMap['mid'] = $mid;   // 필수
        $authMap['authToken'] = $authToken; // 필수
        $authMap['signature'] = $signature; // 필수
        $authMap['timestamp'] = $timestamp; // 필수
        $authMap['charset'] = $charset;  // default=UTF-8
        $authMap['format'] = $format;  // default=XML
        //if(null != notiUrl && notiUrl.length() > 0){
        //  authMap.put("notiUrl"       ,notiUrl);
        //}


        try {

            $httpUtil = new HttpClient();

            //#####################
            // 4.API 통신 시작
            //#####################

            $authResultString = "";
            if ($httpUtil->processHTTP($authUrl, $authMap)) {
                $authResultString = $httpUtil->body;
            } else {
                echo "Http Connect Error\n";
                echo $httpUtil->errormsg;

                throw new Exception("Http Connect Error");
            }

            //############################################################
            //5.API 통신결과 처리(***가맹점 개발수정***)
            //############################################################

            $resultMap = json_decode($authResultString, true);

            $tid = $resultMap['tid'];
            $oid = $resultMap['MOID'];

            $sql = " select * from {$g5['g5_shop_order_data_table']} where od_id = '$oid' ";
            $row = sql_fetch($sql);

            $data = unserialize(base64_decode($row['dt_data']));

            if(isset($data['pp_id']) && $data['pp_id']) {
                $order_action_url = G5_HTTPS_SHOP_URL.'/personalpayformupdate.php';
                $page_return_url  = G5_SHOP_URL.'/personalpayform.php?pp_id='.$data['pp_id'];
            } else {
                $order_action_url = G5_HTTPS_SHOP_URL.'/orderformupdate.php';
                $page_return_url  = G5_SHOP_URL.'/orderform.php';
                if($_SESSION['ss_direct'])
                    $page_return_url .= '?sw_direct=1';
            }

            if (strcmp('0000', $resultMap['resultCode']) == 0) {
                /*                         * ***************************************************************************
                 * 여기에 가맹점 내부 DB에 결제 결과를 반영하는 관련 프로그램 코드를 구현한다.

                  [중요!] 승인내용에 이상이 없음을 확인한 뒤 가맹점 DB에 해당건이 정상처리 되었음을 반영함
                  처리중 에러 발생시 망취소를 한다.
                 * **************************************************************************** */

                // 결제결과 session에 저장
                set_session('resultMap', $resultMap);

                require G5_SHOP_PATH.'/inicis/INIStdPayResult.php';
                exit;
            } else {
                $s = '(오류코드:'.$resultMap['resultCode'].') '.$resultMap['resultMsg'];
                alert($s, $page_return_url);
            }

            // 수신결과를 파싱후 resultCode가 "0000"이면 승인성공 이외 실패
            // 가맹점에서 스스로 파싱후 내부 DB 처리 후 화면에 결과 표시
            // payViewType을 popup으로 해서 결제를 하셨을 경우
            // 내부처리후 스크립트를 이용해 opener의 화면 전환처리를 하세요
            //throw new Exception("강제 Exception");
        } catch (Exception $e) {
            //    $s = $e->getMessage() . ' (오류코드:' . $e->getCode() . ')';
            //####################################
            // 실패시 처리(***가맹점 개발수정***)
            //####################################
            //---- db 저장 실패시 등 예외처리----//
            $s = $e->getMessage() . ' (오류코드:' . $e->getCode() . ')';
            echo $s;

            //#####################
            // 망취소 API
            //#####################

            $netcancelResultString = ""; // 망취소 요청 API url(고정, 임의 세팅 금지)
            if ($httpUtil->processHTTP($netCancel, $authMap)) {
                $netcancelResultString = $httpUtil->body;
            } else {
                echo "Http Connect Error\n";
                echo $httpUtil->errormsg;

                throw new Exception("Http Connect Error");
            }

            echo "## 망취소 API 결과 ##";

            $netcancelResultString = str_replace("<", "&lt;", $$netcancelResultString);
            $netcancelResultString = str_replace(">", "&gt;", $$netcancelResultString);

            echo "<pre>", $netcancelResultString . "</pre>";
            // 취소 결과 확인
        }
    } else {

        //#############
        // 인증 실패시
        //#############
        echo "<br/>";
        echo "####인증실패####";

        echo "<pre>" . var_dump($_REQUEST) . "</pre>";
    }
} catch (Exception $e) {
    $s = $e->getMessage() . ' (오류코드:' . $e->getCode() . ')';
    echo $s;
}
?>