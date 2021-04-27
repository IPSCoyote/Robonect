<?php

// Klassendefinition
class RobonectWifiModul extends IPSModule
{
    /**
     * Die folgenden Funktionen stehen automatisch zur Verfügung, wenn das Modul über die "Module Control" eingefügt wurden.
     * Die Funktionen werden, mit dem selbst eingerichteten Prefix, in PHP und JSON-RPC wiefolgt zur Verfügung gestellt:
     *
     * ABC_MeineErsteEigeneFunktion($id);
     *
     */

    public function Create()
    {
        /* Create is called ONCE on Instance creation and start of IP-Symcon.
           Status-Variables und Modul-Properties for permanent usage should be created here  */
        parent::Create();

        // Properties Robonect Wifi Module
        $this->RegisterPropertyString("IPAddress", '0.0.0.0');
        $this->RegisterPropertyString("Username", '');
        $this->RegisterPropertyString("Password", '');
        $this->RegisterPropertyBoolean( "HTTPUpdateTimer", false );
        $this->RegisterPropertyInteger( "UpdateTimer", 10 );

        // Timer
        $this->RegisterTimer("ROBONECT_UpdateTimer", 0, 'ROBONECT_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        /* Called on 'apply changes' in the configuration UI and after creation of the instance */
        parent::ApplyChanges();

        // Generate Profiles & Variables
        $this->registerProfiles();
        $this->registerVariables();

        // Set Data to Variables (and update timer)
        $this->Update();
    }


    public function Update()
    {

        $semaphore = 'Robonect'.$this->InstanceID.'_Update';
        if ( IPS_SemaphoreEnter( $semaphore, 0 ) == false ) { return false; };

        // HTTP status request
        $data = $this->executeHTTPCommand( 'status' );
        if ($data == false) {
            return false;
        } elseif ( isset( $data['successful'] ) ) {
            // set values to variables

            //--- Identification
            SetValue($this->GetIDForIdent("name"), $data['name']);

            //--- Status
            SetValue($this->GetIDForIdent("status"), $data['status']['status']);

            SetValue($this->GetIDForIdent("distance"), $data['status']['distance']);

            SetValue($this->GetIDForIdent("stopped"), $data['status']['stopped']);

            //--- Timer
            $statusSinceTimestamp = time() - $data['status']['duration'];
            SetValue($this->GetIDForIdent("statusSince"), $statusSinceTimestamp);
            if ( intdiv( $data['status']['duration'], 86400 ) > 0 ) {
                $Text = intdiv($data['status']['duration'], 86400).' Tag';
                if ( intdiv($data['status']['duration']) > 1 ) $Text = $Text.'en';
            } else {
                $Text = "";
                if ( intdiv( $data['status']['duration'], 3600 ) > 0 ) $Text = intdiv( $data['status']['duration'], 3600 )." Stunden ";
                $Text = $Text.date("i", $data['status']['duration'] ). " Minuten";
            }
            SetValue($this->GetIDForIdent("statusSinceDescriptive"), $Text);
            SetValue($this->GetIDForIdent("mode"), $data['status']['mode']);
            SetValue($this->GetIDForIdent("batterySOC"), $data['status']['battery']);
            SetValue($this->GetIDForIdent("hours"), $data['status']['hours']);

            SetValue($this->GetIDForIdent( "TimerStatus"), $data['timer']['status']);

            //--- WLAN
            $WLANIntensity = 100;
            $WLANmDB = $data['wlan']['signal'];
            if ( abs( $WLANmDB ) >= 95 ) {
                $WLANIntensity = 0;
            } else {
                $WLANIntensity = min( max(round(( (95 - abs($WLANmDB)) / 60 ) * 100, 0), 0), 100 );
            }
            SetValue($this->GetIDForIdent( "WLANSignal" ), $WLANIntensity );

            //--- Health
            SetValue($this->GetIDForIdent("HealthTemperature" ), $data['health']['temperature']);
            SetValue($this->GetIDForIdent("HealthHumidity" ), $data['health']['humidity']);

            //--- Clock
            $unixTimestamp = $data['clock']['unix'];
            $dateTimeZoneLocal = new DateTimeZone(date_default_timezone_get());
            $localTime = new DateTime("now", $dateTimeZoneLocal);
            $unixTimestamp = $unixTimestamp - $dateTimeZoneLocal->getOffset($localTime);
            SetValue($this->GetIDForIdent("ClockUnixTimestamp"), $unixTimestamp );

        }

        // Set Timer
        if ( $this->ReadPropertyBoolean( "HTTPUpdateTimer" ) and $this->ReadPropertyInteger("UpdateTimer") >= 10 ) {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", $this->ReadPropertyInteger("UpdateTimer")*1000);
        }

        IPS_SemaphoreLeave( $semaphore );
    }

    public function Start() {
        // start the current modus of the lawnmower; tested
        // get data via HTTP Request
        $data = $this->executeHTTPCommand( 'start' );
        if ( $data == false ) {
            return false;
        } else {
            return $data['successful'];
        }
    }

    public function Stop() {
        // stop the current modus of the lawnmower; tested
        // get data via HTTP Request
        $data = $this->executeHTTPCommand( 'stop' );
        if ( $data == false ) {
            return false;
        } else {
            return $data['successful'];
        }
    }

    public function UpdateErrorList() {
        // $format = initial / "JSON" (return plain json)
        //           Array (returns encoded json)
        //           Http (returns a Http table)

        $semaphore = 'Robonect'.$this->InstanceID.'_ErrorList';
        if ( IPS_SemaphoreEnter( $semaphore, 0 ) == false ) { return false; };

        $data = $this->executeHTTPCommand( 'error' );
        if ( !isset( $data ) or  !isset( $data['errors'] ) or !isset( $data['successful'] ) or ( $data['successful'] != true ) ) { return false; }

        if ( count( $data['errors'] ) == 0 ) { return true; }

        $errorCount = count( $data['errors'] );
        SetValue($this->GetIDForIdent("ErrorCount"), $errorCount );

        $errorListHTML = '<table>';
        $errorListHTML = $errorListHTML.'<colgroup>';
        $errorListHTML = $errorListHTML.'</colgroup>';
        $errorListHTML = $errorListHTML.'<thead><tr>';
        $errorListHTML = $errorListHTML.'<th></th>';
        $errorListHTML = $errorListHTML.'</thead>ID</tr>';
        $errorListHTML = $errorListHTML.'</thead>Datum</tr>';
        $errorListHTML = $errorListHTML.'</thead>Uhrzeit</tr>';
        $errorListHTML = $errorListHTML.'</thead>Fehlercode</tr>';
        $errorListHTML = $errorListHTML.'</thead>Beschreibung</tr>';
        $errorListHTML = $errorListHTML.'<tbody>';

        for ( $x = 0; $x < $errorCount; $x++  ) {
            $error = $data['errors'][$x];
            $colorCode = '#555555';
            if ( ($x % 2 ) != 0 ) $colorCode = '#333333';
            $errorListHTML = $errorListHTML.'<tr style="background-color:"'.$colorCode.'>';

            $index = $x+1;
            $errorListHTML = $errorListHTML.'<td>'.$index.'</td>';
            $errorListHTML = $errorListHTML.'<td>'.$error['date'].'</td>';
            $errorListHTML = $errorListHTML.'<td>'.$error['time'].'</td>';
            $errorListHTML = $errorListHTML.'<td>'.$error['error_code'].'</td>';
            $errorListHTML = $errorListHTML.'<td>'.$error['error_message'].'</td>';

            $errorListHTML = $errorListHTML.'</tr>';
        }

        $errorListHTML = $errorListHTML.'</tbody>';
        $errorListHTML = $errorListHTML.'</table>';

        SetValue($this->GetIDForIdent("ErrorList"), $errorListHTML );

        IPS_SemaphoreLeave( $semaphore );
    }

    public function ClearErrors() {
        $data = $this->executeHTTPCommand('error&clear=1' );
        if ( $data == false ) {
            return false;
        } else {
            if ( $data['successful'] == true ) {
                SetValue($this->GetIDForIdent("Errorcount"), 0 );
            }
            return $data['successful'];
        }
    }

    public function DriveHome() {
        // Mower should drive to home position
        $data = $this->executeHTTPCommand('mode&mode=home' );
        if ( $data == false ) {
            return false;
        } else {
            return $data['successful'];
        }
    }

    public function SetMode( $mode ) {
        // Set Mode of the Mower

        // check parameter
        if ( $mode !== "home" && $mode !== "eod" && $mode !== "man" && $mode !== "auto" ) return false;

        $data = $this->executeHTTPCommand('mode&mode='.$mode );
        if ( $data == false ) {
            return false;
        } else {
            return $data['successful'];
        }
    }

    public function StartMowingNow( int $duration ) {
        // start mowing now for XX minutes and go HOME afterwards (tested)
        if ( $duration > 1440 or $duration < 60 ) return false;

        $start = date( 'H:i', time() );
        $data = $this->executeHTTPCommand('mode&mode=job&start='.$start.'&duration='.$duration.'&after=home' );
        if ( $data == false ) {
            return false;
        } else {
            return $data['successful'];
        }
    }

    public function ScheduleJob( int $duration, string $modeAfter, string $start, string $stop ) {
        // remote start positions are not supported!

        // check parameters
        if ( $duration > 1440 or $duration < 60 ) return false;

        $modeParam = $modeAfter;
        if ( $modeParam == '' ) {
            // default HOME
            $modeParam = 'home';
        }
        if ( $modeParam !== "home" && $modeParam !== "eod" && $modeParam !== "man" && $modeParam !== "auto" ) return false;


        $startParam = $start;
        if ( $startParam == '' ) {
            // default now
            $startParam = date( 'H:i', time() );
        }
        if ( strlen( $startParam ) > 5 ) { return false; }
        if ( preg_match('((1[0-1]1[0-9]|2[0-3]):[0-5][0-9]?)', $startParam ) == false ) { return false; }

        $startIntValue = intval( substr( $startParam, 0, 2 ) ) * 60 + intval( substr( $startParam, 3, 2 ) );

        $stopParam = $stop;
        if ( $stopParam == '' ) {
            // default stop = start + duration + 2min
            $stopIntValue = $startIntValue + $duration + 5;
            $hour = intdiv( $stopIntValue, 60 );
            $minutes = $stopIntValue - ( $hour * 60 );
            if ( $hour > 23 ) { $hour = $hour - 24; };

            $stopParam = '';
            if ( $hour < 10 ) $stopParam = '0';
            $stopParam = $stopParam.$hour.':';
            if ( $minutes < 10 ) $stopParam = $stopParam.'0';
            $stopParam = $stopParam.$minutes;

        }
        if ( strlen( $stopParam ) > 5 ) { return false; }
        if ( preg_match('(([0-1][0-9]|2[0-3]):[0-5][0-9]?)', $stopParam ) == false ) { return false; }

        $stopIntValue = intval( substr( $stopParam, 0, 2 ) ) * 60 + intval( substr( $stopParam, 3, 2 ) );
        if ( $stopIntValue < $startIntValue ) { $stopIntValue = $stopIntValue + 1440; }
        // check duration is not longer than mowing interval
        if ( ( $stopIntValue - $startIntValue ) < $duration ) { return false; }


        //--- execute Command
        $data = $this->executeHTTPCommand('mode&mode=job&start='.$startParam.'&stop='.$stopParam.'&duration='.$duration.'&after='.$modeParam );
        if ( $data == false ) {
            return false;
        } else {
            return $data['successful'];
        }
    }


    protected function executeHTTPCommand( $command ) {
        $IPAddress = trim($this->ReadPropertyString("IPAddress"));
        $Username = trim($this->ReadPropertyString("Username"));
        $Password = trim($this->ReadPropertyString("Password"));

        if ( $command == '' ) return false;

        // HTTP status request
        $URL = 'http://' . $IPAddress . '/json?cmd='.$command;
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $URL,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $Username . ':' . $Password,
                CURLOPT_TIMEOUT => 30
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $json = curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            return false;
        };
        return json_decode( $json, true );
    }


    protected function registerProfiles()
    {
        // Generate Variable Profiles
        if (!IPS_VariableProfileExists('ROBONECT_Status')) {
            IPS_CreateVariableProfile('ROBONECT_Status', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Status', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 0, "Status wird ermittelt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 1, "geparkt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 2, "mäht", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 3, "sucht die Ladestation", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 4, "lädt", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 5, "sucht", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 7, "Fehlerstatus", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 8, "Schleifensignal verloren", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 16, "abgeschaltet", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Status", 17, "schläft", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_Modus')) {
            IPS_CreateVariableProfile('ROBONECT_Modus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Modus', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 0, "automatisch", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 1, "manuell", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 2, "Zuhause", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 3, "Demo", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_TimerStatus')) {
            IPS_CreateVariableProfile('ROBONECT_TimerStatus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_TimerStatus', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 0, "deaktiviert", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 1, "aktiv", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 2, "Standby", "", 0xFFFFFF);
        }




        if ( !IPS_VariableProfileExists('ROBONECT_JaNein') ) {
            IPS_CreateVariableProfile('ROBONECT_JaNein', 0 );
            IPS_SetVariableProfileIcon('ROBONECT_JaNein', '' );
            IPS_SetVariableProfileAssociation("ROBONECT_JaNein", true, "Ja", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_JaNein", false, "Nein", "", 0xFFFFFF);
        }

        if ( !IPS_VariableProfileExists('ROBONECT_Stunden') ) {
            IPS_CreateVariableProfile('ROBONECT_Stunden', 1 );
            IPS_SetVariableProfileDigits('ROBONECT_Stunden', 0 );
            IPS_SetVariableProfileIcon('ROBONECT_Stunden', 'Clock' );
            IPS_SetVariableProfileText('ROBONECT_Stunden', "", " h" );
        }

    }

    protected function registerVariables()
    {

        //--- Basic Data ---------------------------------------------------------
        $this->RegisterVariableString("name", "Name", "", 0);

        //--- Status -------------------------------------------------------------
        $this->RegisterVariableInteger("status", "Status", "ROBONECT_Status", 10);
        $this->RegisterVariableInteger("distance", "Entfernung", "", 11);
        $this->RegisterVariableBoolean("stopped", "man. angehalten", "ROBONECT_JaNein", 12);
        $this->RegisterVariableInteger("statusSince", "Status seit", "~UnixTimestamp", 13);
        $this->RegisterVariableString("statusSinceDescriptive", "Status seit", "", 14);
        $this->RegisterVariableInteger("mode", "Modus", "ROBONECT_Modus", 15);
        $this->RegisterVariableInteger("batterySOC", "Akkustand", "~Battery.100", 16);
        $this->RegisterVariableInteger("hours", "Arbeitsstunden", "ROBONECT_Stunden", 17);
        $this->RegisterVariableInteger( "ErrorCount", "Anzahl Fehlermeldungen", "", 20 );
        $this->RegisterVariableString( "ErrorList", "Fehlermeldungen", "~HTMLBox", 21 );

        //--- Timer --------------------------------------------------------------
        $this->RegisterVariableInteger( "TimerStatus", "Timer Status", "ROBONECT_TimerStatus", 30 );

        //--- WLAN ---------------------------------------------------------------
        $this->RegisterVariableInteger( "WLANSignal", "WLAN Signalstärke", "~Intensity.100", 50 );

        //--- Health -------------------------------------------------------------
        $this->RegisterVariableFloat( "HealthTemperature", "Temperatur im Rasenmäher", "~Temperature", 60 );
        $this->RegisterVariableInteger( "HealthHumidity", "Feuchtigkeit im Rasenmäher",  "~Humidity", 61 );

        //--- Clock -------------------------------------------------------------
        $this->RegisterVariableInteger( "ClockUnixTimestamp", "Interner Unix Zeitstempel", "~UnixTimestamp", 65 );

    }

}