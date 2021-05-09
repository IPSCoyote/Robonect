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
        $this->RegisterPropertyString("MQTTTopic", '');
        $this->RegisterPropertyInteger( "UpdateTimer", 10 );
        $this->RegisterPropertyInteger( "MowingTime", 180 );


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
            if ( ( $data['status']['stopped'] == false ) and ( GetValueInteger($this->GetIDForIdent("manualAction")) == 2 ) ) {
                // "Pause" als Aktion gehighlighted, aber Mäher nicht gestoppt
                SetValue($this->GetIDForIdent("manualAction"), -1 ); // keine Aktion im Webfront gehighlighted
            } elseif ( $data['status']['stopped'] == true ) {
                SetValue($this->GetIDForIdent("manualAction"), 1 ); // "Pause" Aktion highlighten
            }


            //--- Timer
            $statusSinceTimestamp = time() - $data['status']['duration'];
            SetValue($this->GetIDForIdent("statusSince"), $statusSinceTimestamp);
            if ( intdiv( $data['status']['duration'], 86400 ) > 0 ) {
                $Text = intdiv($data['status']['duration'], 86400).' Tag';
                if ( intdiv($data['status']['duration'], 86400) > 1 ) $Text = $Text.'en';
            } else {
                $Text = "";
                if ( intdiv( $data['status']['duration'], 3600 ) > 0 ) $Text = intdiv( $data['status']['duration'], 3600 )." Stunden ";
                $Text = $Text.date("i", $data['status']['duration'] ). " Minuten";
            }
            SetValue($this->GetIDForIdent("statusSinceDescriptive"), $Text);
            SetValue($this->GetIDForIdent("mode"), $data['status']['mode']);
            if ( $data['status']['mode'] == 0 ) {
                SetValue($this->GetIDForIdent("modeInteractive"), 1); // automatisch = Timer
            } else {
                SetValue($this->GetIDForIdent("modeInteractive"), 0); // sonst = manuell
            }

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
        } else {
            $this->SetTimerInterval("ROBONECT_UpdateTimer", 0 );
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

    public function SetMode( string $mode ) {
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
        $durationToRun = $duration;
        if ( $durationToRun == 0 ) {
            $durationToRun = $this->ReadPropertyInteger("MowingTime");
        }
        if ( $durationToRun > 1440 or $durationToRun < 10 ) return false;

        $start = date( 'H:i', time() );
        $data = $this->executeHTTPCommand('mode&mode=job&start='.$start.'&duration='.$durationToRun.'&after=home' );
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

    public function GetTimerFromMower() {
        // reads all timer information and transfers it to an IPS Timer Instance

        define( 'WEEKDAYS', ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'] );

        $data = $this->executeHTTPCommand( 'timer' );
        if ( !isset( $data ) or  !isset( $data['timer'] ) or !isset( $data['successful'] ) or ( $data['successful'] != true ) ) { return false; }

        if ( $this->GetIDForIdent('TimerPlanActive' ) == false ) { return false; }

        $weekPlanEventID = IPS_GetEventIDByName( 'Timer Wochen Plan', $this->GetIDForIdent('TimerPlanActive' ));
        if ( $weekPlanEventID == false ) { return false; }

        // delete and re-create the ScheduleGroups to rebuild them from scratch
        IPS_SetEventScheduleGroup( $weekPlanEventID, 0, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 1, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 2, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 3, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 4, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 5, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 6, 0 );
        IPS_SetEventScheduleGroup( $weekPlanEventID, 0, 1);  // Mon
        IPS_SetEventScheduleGroup( $weekPlanEventID, 1, 2);  // Tue
        IPS_SetEventScheduleGroup( $weekPlanEventID, 2, 4);  // Wed
        IPS_SetEventScheduleGroup( $weekPlanEventID, 3, 8);  // Thu
        IPS_SetEventScheduleGroup( $weekPlanEventID, 4, 16); // Fri
        IPS_SetEventScheduleGroup( $weekPlanEventID, 5, 32);  // Sat
        IPS_SetEventScheduleGroup( $weekPlanEventID, 6, 64); // Sun
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 0, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 1, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 2, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 3, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 4, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 5, 1, 0, 0, 0, 1);
        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, 6, 1, 0, 0, 0, 1);

        $timerList = $data['timer'];
        for ( $x = 0; $x<=13; $x++ ) {

            $timer = $timerList[$x];
            if ( $timer['enabled'] == true ) {

                for ( $weekday = 0; $weekday <= 6; $weekday = $weekday + 1 ) {
                    if ( $timer['weekdays'][WEEKDAYS[$weekday]] ) {
                        $startHour = intval( substr( $timer['start'], 0, 2 ) );
                        $startMinutes = intval( substr( $timer['start'], 3, 2 ) );
                        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, $weekday, 2, $startHour, $startMinutes, 0, 2 );
                        $stopHour = intval( substr( $timer['end'], 0, 2 ) );
                        $stopMinutes = intval( substr( $timer['end'], 3, 2 ) );
                        IPS_SetEventScheduleGroupPoint( $weekPlanEventID, $weekday, 3, $stopHour, $stopMinutes, 0, 1 );
                    }
                }

            }
        }

        return $data;
    }

    public function SetTimerToMower() {
        // transfer the wekk plan timer to the mower
        // Wochenplan auslesen

        $TimerPlanActiveID = $this->GetIDForIdent("TimerPlanActive");

        if ( $TimerPlanActiveID == false ) {
            return false;
        }
        $WochenplanEventID = @IPS_GetEventIDByName( 'Timer Wochen Plan', $TimerPlanActiveID );
        if ( $WochenplanEventID === false ) {
            return;
        }
        $Wochenplan = IPS_GetEvent($WochenplanEventID);

        $listOfWeekdays = array("mo","tu","we","th","fr","sa","su");
        $emptyWeekdays = json_decode( '{"mo": false, "tu": false, "we": false, "th": false, "fr": false, "sa": false, "su": false}', true );
        $emptyTimer = json_decode( '{"id": 0, "enabled": false, "start": "08:00", "end": "18:00", "weekdays": {"mo": false, "tu": false, "we": false, "th": false, "fr": false, "sa": false, "su": false}}', true);
        $timerID = 1;
        $timerList = [];

        // über die Schedule-Gruppen (Tage) loopen
        if ( isset( $Wochenplan ) and isset( $Wochenplan['ScheduleGroups'] ) ) {
            for( $tag = 0; $tag <= 6; $tag = $tag + 1 ) {
                // Montag (Tag 0) - Sonntag (Tag 6)

                if ( isset( $Wochenplan['ScheduleGroups'][$tag] ) and
                    isset( $Wochenplan['ScheduleGroups'][$tag]['Points'] ) and
                    count($Wochenplan['ScheduleGroups'][$tag]['Points'] ) > 0 ) {
                    $maehenGestartet = false;
                    $StartZeit = "";

                    for( $x = 0; $x < count($Wochenplan['ScheduleGroups'][$tag]['Points']); $x++ ) {
                        if ( ( $maehenGestartet == false ) and ( $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['ActionID'] == 2 ) ) {
                            // ActionID = 2 => mähenStarten; nur, wenn maehen nicht gestartet einen neuen Timer planen
                            $timer['id']       = $timerID;
                            $timer['enabled']  = true;
                            $timer['start']    = '';
                            $timer['end']      = '';
                            $timer['weekdays'] = [];

                            $StartStunde = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Hour'];
                            if ( strlen( $StartStunde ) == 1 ) $StartStunde = '0'.$StartStunde;
                            $StartMinute = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Minute'];
                            if ( strlen( $StartMinute ) == 1 ) $StartMinute = '0'.$StartMinute;
                            $StartZeit = $StartStunde.":".$StartMinute;
                            $timer['start'] = $StartZeit;
                            $timer['weekdays'] = $emptyWeekdays;
                            $timer['weekdays'][$listOfWeekdays[$tag]] = true;
                            $maehenGestartet = true;
                            continue;
                        }
                        if ( ( $maehenGestartet == true ) and ( $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['ActionID'] == 1 ) ) {
                            // ActionID = 1 => mähenBeenden; nur, wenn maehen gestartet das Event beenden

                            $StopStunde = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Hour'];
                            if ( strlen( $StopStunde ) == 1 ) $StopStunde = '0'.$StopStunde;
                            $StopMinute = $Wochenplan['ScheduleGroups'][$tag]['Points'][$x]['Start']['Minute'];
                            if ( strlen( $StopMinute ) == 1 ) $StopMinute = '0'.$StopMinute;

                            $StopZeit = $StopStunde.":".$StopMinute;
                            if ( $StopZeit == '00:00' ) $StopZeit = '23:59';

                            $timer['end'] = $StopZeit;

                            // prüfen, ob der Timer neu ist, oder ein bereits vorhandener genutzt werden kann
                            for ($y = 0; $y < count( $timerList ); $y++ ) {
                                if ( $timerList[$y]['start'] == $timer['start'] and
                                    $timerList[$y]['end']   == $timer['end'] ) {
                                    // Timer passt, also Wochentag hinzufügen und neue Timer verwerfen
                                    $timerList[$y]['weekdays'][$listOfWeekdays[$tag]] = true;
                                    $timer['id'] = '';
                                }
                            }

                            if ( $timer['id'] == $timerID ) {
                                // neuer Timer
                                array_push( $timerList, $timer);
                                $timerID++;
                            }

                            $maehenGestartet = false;
                            continue;
                        }
                    }
                }

                if ( $maehenGestartet == true ) {
                    // eine Mähung fängt vor 0:00 an und hört auf 0:00 auf => kein stop mähen an dem Tag!
                    $timer['end'] = '23:59';
                    // prüfen, ob der Timer neu ist, oder ein bereits vorhandener genutzt werden kann
                    for ($y = 0; $y < count( $timerList ); $y++ ) {
                        if ( $timerList[$y]['start'] == $timer['start'] and
                            $timerList[$y]['end']   == $timer['end'] ) {
                            // Timer passt, also Wochentag hinzufügen und neue Timer verwerfen
                            $timerList[$y]['weekdays'][$listOfWeekdays[$tag]] = true;
                            $timer['id'] = '';
                        }
                    }

                    if ( $timer['id'] == $timerID ) {
                        // neuer Timer
                        array_push( $timerList, $timer);
                        $timerID++;
                    }

                    $maehenGestartet = false;
                }
            }
        };

        $missingTimers = 14-count( $timerList );
        for( $z=0; $z <= $missingTimers; $z++ ) {
            $timer = $emptyTimer;
            $timer['id'] = $timerID;
            $timerID++;
            array_push( $timerList, $timer );
        }

        // Robonect Programmieren
        $success = true;
        for ( $x = 0; $x <= 13; $x++ ) {

            $cmd = 'timer&timer='.$timerList[$x]['id'].'&start='.$timerList[$x]['start'].'&end='.$timerList[$x]['end'];
            for ( $tag = 0; $tag <= 6; $tag++ ) {
                if ( $timerList[$x]['weekdays'][$listOfWeekdays[$tag]] == true ) {
                    $cmd = $cmd.'&'.$listOfWeekdays[$tag].'=1';
                } else {
                    $cmd = $cmd.'&'.$listOfWeekdays[$tag].'=0';
                }
            }
            if ( $timerList[$x]['enabled'] == true ) {
                $cmd = $cmd."&enable=1";
            } else {
                $cmd = $cmd."&enable=0";
            }

            // Kommando senden
            $success = $success and $this->executeHTTPCommand( $cmd );
        }

        return $success;
    }

    protected function executeHTTPCommand( $command ) {
        $IPAddress = trim($this->ReadPropertyString("IPAddress"));
        $Username = trim($this->ReadPropertyString("Username"));
        $Password = trim($this->ReadPropertyString("Password"));

        // check if IP is ocnfigured and valid
        if ( $IPAddress == "0.0.0.0" ) {
            $this->SetStatus(200); // no configuration done
            return false;
        } elseif (filter_var($IPAddress, FILTER_VALIDATE_IP) == false) {
            $this->SetStatus(201); // no valid IP configured
            return false;
        }

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
            $this->SetStatus(203 ); // no valid IP configured
            return false;
        };
        $this->SetStatus(102 ); // Robonect found
        return json_decode( $json, true );
    }

    public function RequestAction($Ident, $Value)
    {

        switch ($Ident) {
            case "modeInteractive":
                switch( $Value ) {
                    case 0: // manuell
                        if ( $this->SetMode( 'man' ) ) {
                            SetValue($this->GetIDForIdent("modeInteractive"), $Value );
                        }
                        break;
                        break;
                    case 1: // Timer auto
                        if ( $this->SetMode( 'auto' ) ) {
                            SetValue($this->GetIDForIdent("modeInteractive"), $Value );
                        }
                        break;
                }
                break;

            case "manualAction":
                switch( $Value ) {
                    case 0: // jetzt mähen
                        if ($this->StartMowingNow(0) ) {
                            SetValue($this->GetIDForIdent("manualAction"), $Value);
                        }
                        break;

                    case 1: // pause / weitermachen
                        // wir müssen zunächst sicher sein, das "manuell gestoppt" sauber sitzt
                        $this->Update();
                        if ( GetValueBoolean($this->GetIDForIdent('stopped')) == false ) {
                            if ( $this->Stop() ) {
                                SetValue($this->GetIDForIdent("manualAction"), 1);
                            }
                        } else {
                            if ( $this->Start() ) {
                                SetValue($this->GetIDForIdent("manualAction"), -1);
                            }
                        }
                        $this->Update(); // Werte neu ermitteln
                        break;

                    case 2: // mähen beenden
                        if ( $this->SetMode( 'home' ) ) {
                            SetValue($this->GetIDForIdent("manualAction"), $Value );
                        }
                        $this->Update();
                        break;
                }
                break;
        }
    }

    public function ReceiveData($String) {
        $mqttTopic = $this->ReadPropertyString("MQTTTopic");
     /*   $Data = json_decode($String);
        if ( isset( $Data ) and isset( $Data['Buffer'] ) ) {
            $Buffer = $Data['Buffer'];
            $pos = strpos( $Buffer, $mqttTopic, 0 );
            if ( $pos !== false ) {
                $content = substr( $Buffer, $pos+strlen($mqttTopic), 255 );
                IPS_LogMessage('Robonect', $content );
            }
        } */
    }

    protected function registerProfiles()
    {
        // Generate Variable Profiles
        if (!IPS_VariableProfileExists('ROBONECT_Status')) {
            IPS_CreateVariableProfile('ROBONECT_Status', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Status', '');
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

        if (!IPS_VariableProfileExists('ROBONECT_InteractiveMode')) {
            IPS_CreateVariableProfile('ROBONECT_InteractiveMode', 1);
            IPS_SetVariableProfileIcon('ROBONECT_InteractiveMode', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_InteractiveMode", 0, "manuell", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_InteractiveMode", 1, "Timer", "Clock", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_Modus')) {
            IPS_CreateVariableProfile('ROBONECT_Modus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_Modus', '');
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 0, "automatisch", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 1, "manuell", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 2, "Zuhause", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_Modus", 3, "Demo", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_TimerStatus')) {
            IPS_CreateVariableProfile('ROBONECT_TimerStatus', 1);
            IPS_SetVariableProfileIcon('ROBONECT_TimerStatus', '');
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 0, "deaktiviert", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 1, "aktiv", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_TimerStatus", 2, "Standby", "", 0xFFFFFF);
        }

        if (!IPS_VariableProfileExists('ROBONECT_ManualAction')) {
            IPS_CreateVariableProfile('ROBONECT_ManualAction', 1);
            IPS_SetVariableProfileIcon('ROBONECT_ManualAction', 'Ok');
            IPS_SetVariableProfileAssociation("ROBONECT_ManualAction", 0, "jetzt mähen", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_ManualAction", 1, "pause", "", 0xFFFFFF);
            IPS_SetVariableProfileAssociation("ROBONECT_ManualAction", 2, "mähen beenden", "", 0xFFFFFF);
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
        $this->RegisterVariableInteger( "modeInteractive", "Modus", "ROBONECT_InteractiveMode", 1);
        $this->EnableAction("modeInteractive");
        $this->RegisterVariableInteger( "manualAction", "Aktion", "ROBONECT_ManualAction", 2 );
        $this->EnableAction("manualAction");

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

        $TimerPlanActiveID = $this->RegisterVariableBoolean( "TimerPlanActive", "Timer-Plan aktiv", "ROBONECT_JaNein", 31 );
        // check, if timer Plan Active is already there
        if ( @IPS_GetEventIDByName( 'Timer Wochen Plan', $TimerPlanActiveID ) == false ) {
            $weekPlanID = IPS_CreateEvent(2); // Weekplan
            IPS_SetParent($weekPlanID, $TimerPlanActiveID);
            IPS_SetName($weekPlanID, 'Timer Wochen Plan');

            IPS_SetEventScheduleGroup($weekPlanID, 0, 1);  // Mon
            IPS_SetEventScheduleGroup($weekPlanID, 1, 2);  // Tue
            IPS_SetEventScheduleGroup($weekPlanID, 2, 4);  // Wed
            IPS_SetEventScheduleGroup($weekPlanID, 3, 8);  // Thu
            IPS_SetEventScheduleGroup($weekPlanID, 4, 16); // Fri
            IPS_SetEventScheduleGroup($weekPlanID, 5, 32);  // Sat
            IPS_SetEventScheduleGroup($weekPlanID, 6, 64); // Sun

            IPS_SetEventScheduleAction($weekPlanID, 1, "mähen beenden", 0x000000, "SetValueBoolean(\$_IPS['TARGET'], false);");
            IPS_SetEventScheduleAction($weekPlanID, 2, "mähen beginnen", 0x00FF00, "SetValueBoolean(\$_IPS['TARGET'], true);");

            IPS_SetEventScheduleGroupPoint($weekPlanID, 0, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 1, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 2, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 3, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 4, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 5, 1, 0, 0, 0, 1);
            IPS_SetEventScheduleGroupPoint($weekPlanID, 6, 1, 0, 0, 0, 1);

            IPS_SetEventActive($weekPlanID, true);
        }


        //--- WLAN ---------------------------------------------------------------
        $this->RegisterVariableInteger( "WLANSignal", "WLAN Signalstärke", "~Intensity.100", 50 );

        //--- Health -------------------------------------------------------------
        $this->RegisterVariableFloat( "HealthTemperature", "Temperatur im Rasenmäher", "~Temperature", 60 );
        $this->RegisterVariableInteger( "HealthHumidity", "Feuchtigkeit im Rasenmäher",  "~Humidity", 61 );

        //--- Clock -------------------------------------------------------------
        $this->RegisterVariableInteger( "ClockUnixTimestamp", "Interner Unix Zeitstempel", "~UnixTimestamp", 65 );

    }

}