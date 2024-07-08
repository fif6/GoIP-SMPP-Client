<?php

include("GoIP_SMPP_Client.php");


GoIP_SMPP_Client::Configure('1.2.3.4', '7777', 'user01', 'paspas'); // Note: username string contains two parts. 1st is username ('user'), 2nd is postfix of channel number ('01', '02' or etc.)
GoIP_SMPP_Client::Connect();

// Send SMS #1
GoIP_SMPP_Client::Send('Test1-'.rand(100,999), '+123456789');
sleep(20);

// Send SMS #2
GoIP_SMPP_Client::Send('Test2-'.rand(100,999), '+123456789');

GoIP_SMPP_Client::Disconnect();


?>