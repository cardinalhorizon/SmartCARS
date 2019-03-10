<?php

/*
 * Virtual Aviation Operations System 2.0 smartCARS 2 Client Integration
 *
 * Original Frame file conversion by Dennis Daletzki and Mario Feher
 * Module Conversion from VAOS 1.0 to VAOS 2.0 by Taylor Broad
 *
 * For Support, please email support@fsvaos.net
 *
 */

namespace Modules\SmartCARS\Http\Controllers;


use App\Classes\VAOS_Airports;
use App\Classes\VAOS_Flights;
use App\Models\AircraftGroup;
use App\Models\Airline;
use App\Models\Flight;
use App\Classes\VAOS_Schedule;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Schedule;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS\Entities\SCSession as SmartCARS_Session;
use App\User;
use App\Models\FlightData as ACARSData;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;

class SmartCARS
{
    static function manuallogin($userid, $password, $sessionid) {
        $user = $userid;
        if(strpos($user, '@') == false) {
            //search user via username
            $user        = User::where('username', $userid)->first();
            $type = 'username';
            $credentials = [
                'email'    => $user->email,
                'password' => $password, ];
        }
        else {
            //search user via email
            $type = 'email';
            $credentials = [
                'email'    => $userid,
                'password' => $password, ];
        }

        if (Auth::attempt($credentials, ['status' => 1])) {
            // we logged in.
            $ret['dbid'] = Auth::user()->id;
            $ret['code'] = "";
            //$newpltid = $res['username'] + PILOTID_OFFSET;
            $var = "";
            //for($i = strlen($newpltid); $i < PILOTID_LENGTH; $i++)
            //	$var .= "0";
            $ret['pilotid'] = Auth::user()->username;

            //$ret['pilotid'] = $res['pilotid'] + PILOTID_OFFSET;
            $ret['firstname'] = Auth::user()->first_name;
            $ret['lastname'] = Auth::user()->last_name;
            $ret['email'] = Auth::user()->email;
            $ret['ranklevel'] = 1;//$res['ranklevel'];
            $ret['rankstring'] = "Captain";//$res['rank'];
            $ret['result'] = "ok";

            return $ret;
        } else {
            return null;
        }
    }

    static function automaticlogin($dbid, $oldsessionid, $sessionid) {
        $ret = array();
        $res1 = SmartCARS_Session::where('user_id', $dbid)->where('session_key',$oldsessionid)->first();

        if($res1) {
            $res = User::find($dbid);
            if($res) {
                /*
                if(skip_retired_check == false) {
                    if($res['retired'] != "0") {
                        $ret['result'] = "inactive";
                        return $ret;
                    }
                }
                */
                if($res->status == "0") {
                    $ret['result'] = "unconfirmed";
                    return $ret;
                }

                $ret['dbid'] = $res->id;
                $ret['code'] = "";//$res['code'];

                $ret['pilotid'] = $res->username;

                //$ret['pilotid'] = $res['pilotid'] + PILOTID_OFFSET;
                $ret['firstname'] = $res->first_name;
                $ret['lastname'] = $res->last_name;
                $ret['email'] = $res->email;
                $ret['ranklevel'] = 1;//$res['ranklevel'];
                $ret['rankstring'] = "Captain";//$res['rank'];
                $ret['result'] = "ok";
            }
            else
                $ret['result'] = "failed";
        }
        else
            $ret['result'] = "failed";
        return $ret;
    }

    static function verifysession($dbid, $sessionid) {

        $ret = array();
        $res1 = SmartCARS_Session::where('user_id', $dbid)->where('session_key',$sessionid)->first();

        if($res1) {
            $res = User::find($dbid);
            if($res && $res->status != 0) {
                $ret['result'] = "SUCCESS";
                $ret['firstname'] = $res->first_name;
                $ret['lastname'] = $res->last_name;
                return $ret;
            }
            else {
                $ret['result'] = "FAILED";
                return $ret;
            }
        }
        else {
            $ret['result'] = "FAILED";
            return $ret;
        }
    }

    static function getpilotcenterdata($dbid) {
        $res = User::with('avgLandingRate', 'totalFlightTime', 'totalFlights')->find($dbid);
        $ret = array();
        if($res) {
            $ret['totalhours'] = $res->totalFlightTime;
            $ret['totalflights'] = $res->totalFlights;
            $ret['averagelandingrate'] = $res->avgLandingRate;
            $ret['totalpireps'] = $res->totalFlights;
            //}
            //else {
            //	$ret['averagelandingrate'] = "N/A";
            //	$ret['totalpireps'] = "0";
            //}
        }
        return $ret;
    }

    static function getairports($dbid) {

        return Airport::orderBy('icao')->get();

    }

    static function getaircraft($dbid) {
        return Aircraft::orderBy('name')->get();
    }

    static function getbidflights($user_id) {

        $flights = Flight::where(['user_id' => $user_id, ['state', '<=', 1]])->with('depapt')->with('arrapt')->with('airline')->with('aircraft')->get();

        // let's get the proper code
        $export  = [];
        //dd($flights);
        $c = 0;
        foreach ($flights as $flight) {
            if (isset($flight->airline->icao))
            {
                $code = $flight->airline->icao;
            }
            else {
                $code = '';
            }
            $export[$c]['bidid']            = $flight['id'];
            $export[$c]['routeid']          = $flight['id'];
            $export[$c]['code']             = $code;
            $export[$c]['flightnumber']     = $flight['flightnum'];
            $export[$c]['type']             = 'P';
            $export[$c]['departureicao']    = $flight['depapt']['icao'];
            $export[$c]['arrivalicao']      = $flight['arrapt']['icao'];
            $export[$c]['route']            = $flight['route'];
            $export[$c]['cruisingaltitude'] = '';
            $export[$c]['aircraft']         = $flight['aircraft_id'];
            $export[$c]['duration']         = '0.00';
            $export[$c]['departuretime']    = $flight['deptime'];
            $export[$c]['arrivaltime']      = $flight['arrtime'];
            $export[$c]['load']             = '0';
            $export[$c]['daysofweek']       = '0123456';
            // Iterate through the array
            $c++;
        }

        return $export;
    }

    static function bidonflight($dbid, $routeid) {
        $schedule = Schedule::find($routeid);

        if($schedule) {
            // return 1; Schedule is BIDDED
            $bid = VAOS_Schedule::fileBid($dbid, $routeid);
            if ($bid) {
                return 0;
            }

        }
        return 2;
    }

    static function deletebidflight($dbid, $bidid) {

        return Flight::where('id', $bidid)->where('user_id', $dbid)->delete();

    }

    static function searchpireps($dbid, $departureicao, $arrivalicao, $startdate, $enddate, $aircraft, $status) {
        $query = Flight::where('user_id', $dbid);

        if($departureicao !== '')
        {
            $apt = Airport::where('icao', strtoupper($departureicao))->first();
            $id = ($apt) ? $apt->id : -1;
            $query = $query->where('depapt_id', $id);
        }

        if($arrivalicao !== '')
        {
            $apt = Airport::where('icao', strtoupper($arrivalicao))->first();
            $id = ($apt) ? $apt->id : -1;
            $query = $query->where('arrapt_id', $id);
        }

        if($startdate !== '')
        {
            $sdate = Carbon::parse($startdate);
            $query = $query->where('created_at', '>=', $sdate);
        }

        if($enddate !== '')
        {
            $edate = Carbon::parse($enddate);
            $query = $query->where('created_at', '<=', $edate);
        }

        if($aircraft !== '')
        {
            $aircrafts = Aircraft::where('name', $aircraft)->get();
            $ids = $aircrafts->map(function($item){
                return $item->id;
            });
            $query = $query->whereIn('aircraft_id', $ids);
        }

        $ret = $query->with('airline','depapt','arrapt','aircraft')->get();
        return $ret;

    }

    static function getpirepdata($dbid, $pirepid) {
        $pirep = Flight::find($pirepid);

        $ret = array();
        $ret['duration'] = $pirep->flighttime;
        $ret['landingrate'] = $pirep->landingrate;
        $ret['fuelused'] = $pirep->fuel_used;
        $ret['status'] = $pirep->status;
        $ret['log'] = $pirep->flight_data;

        return $ret;
    }

    static function searchflights($dbid, $departureicao, $mintime, $maxtime, $arrivalicao, $aircraft) {
        $query = Schedule::orderby('created_at', 'desc');

        if($departureicao != '')
        {
            $apt = Airport::where('icao', strtoupper($departureicao))->first();
            $id = ($apt) ? $apt->id : -1;
            $query = $query->where('depapt_id', $id);
        }

        if($arrivalicao != '')
        {
            $apt = Airport::where('icao', strtoupper($arrivalicao))->first();
            $id = ($apt) ? $apt->id : -1;
            $query = $query->where('arrapt_id', $id);
        }

        if($aircraft != '')
        {
            $aircrafts = Aircraft::where('name', $aircraft)->with('aircraft_group')->get();
            $ids = $aircrafts->map(function($item){
                return $item->aircraft_group->first()->id;
            });
            $query = $query->whereIn('aircraft_group_id', $ids);
        }

        $ret = $query->with('depapt')->with('arrapt')->with('airline')->with('aircraft_group', 'aircraft_group.aircraft')->get();
        foreach ($ret as $r)
        {
            foreach ($r->aircraft_group as $a) {
                if ($a['pivot']['primary']) {
                    $r->primary_aircraft = $a['aircraft'][0]['id'];
                    break;
                }
            }
        }
        return $ret;
    }

    static function createflight($dbid, $flightcode, $flightnumber, $ticketprice, $depicao, $arricao, $aircraft, $flighttype, $deptime, $arrtime, $flighttime, $route, $cruisealtitude, $distance) {
        try {
            $flight = new Flight();
            // First, resolve the code if there is one
            if (!isEmpty($flightcode)) {
                $flight->airline()->associate(Airline::where('icao', $flightcode)->first());
                $flight->flightnum = $flightnumber;
                $flight->callsign = $flightcode . $flightnumber;
            } else {
                // ok now we know that this ain't associated with an airline. Probably GA, so grab the tail and slide it into things.
                $acf = Aircraft::findOrFail($aircraft);
                $flight->aircraft()->associate($acf);
                $flight->callsign = $acf->registration;
            }
            $flight->depapt()->associate(VAOS_Airports::checkOrAdd($depicao));
            $flight->depapt()->associate(VAOS_Airports::checkOrAdd($arricao));
            $flight->route = $route;
            $flight->state = 0;

            $flight->save();
            return true;
        }
        catch (\Exception $e)
        {
            Log::error($e);
            return false;
        }
    }

    static function positionreport($dbid, $flightnumber, $latitude, $longitude, $magneticheading, $trueheading, $altitude, $groundspeed, $departureicao, $arrivalicao, $phase, $arrivaltime, $departuretime, $distanceremaining, $route, $timeremaining, $aircraft, $onlinenetwork) {
        $pilotdet = User::find($dbid);

        $phases = array(
            "Preflight",
            "Pushing Back",
            "Taxiing to Runway",
            "Taking Off",
            "Climbing",
            "Cruising",
            "Descending",
            "Approaching",
            "Final Approach",
            "Landing",
            "Taxiing to Gate",
            "Awaiting Arrival", /* An intermediary state when smartCARS has detected the aircraft is ready to arrive but the pilot hasn't clicked "End Flight" yet. */
            "Arrived"
        );

        $lat = str_replace(",", ".", $latitude);
        $lon = str_replace(",", ".", $longitude);

        $lat = doubleval($lat);
        $lon = doubleval($lon);

        if($lon < 0.005 && $lon > -0.005)
            $lon = 0;

        if($lat < 0.005 && $lat > -0.005)
            $lat = 0;

        $fields = array(
            'pilotid' =>$dbid,
            'flightnum' =>$flightnumber,
            'pilotname' => $pilotdet->first_name . " " . $pilotdet->last_name,
            'aircraft' =>$aircraft,
            'lat' =>$lat,
            'lon' =>$lon,
            'heading' =>$magneticheading,
            'altitude' =>$altitude,
            'groundspeed' =>$groundspeed,
            'depicao' =>$departureicao,
            'arricao' =>$arrivalicao,
            'deptime' =>$departuretime,
            'arrtime' =>$arrivaltime,
            'route' =>$route,
            'distremain' =>$distanceremaining,
            'timeremaining' =>$timeremaining,
            'phase' =>$phases[$phase],
            'online' => $onlinenetwork,
            'client' =>'smartCARS',
        );

        // find if the row exists
        $flight = self::getProperFlightNum($flightnumber, $dbid);
        $rpt = new ACARSData();
        $rpt->user()->associate($fields['pilotid']);
        $rpt->flight()->associate($flight->id);
        $rpt->lat           = $fields['lat'];
        $rpt->lon           = $fields['lon'];
        $rpt->heading       = $fields['heading'];
        $rpt->altitude      = $fields['altitude'];
        $rpt->groundspeed   = $fields['groundspeed'];
        $rpt->phase         = $fields['phase'];
        $rpt->client        = $fields['client'];
        //$rpt->distremain    =  $report['distremain'];
        $rpt->timeremaining = $fields['timeremaining'];
        $rpt->online        =  $fields['online'];
        $rpt->save();

        $flight->lat = $fields['lat'];
        $flight->lon = $fields['lon'];
        //$flight->heading = $data[38];
        $flight->altitude = $fields['altitude'];
        $flight->gs = $fields['groundspeed'];
        $flight->save();

        return true;
    }
    static function filepirep($dbid, $code, $flightnumber, $routeid, $bidid, $departureicao, $arrivalicao, $route, $aircraft, $load, $flighttime, $landingrate, $comments, $fuelused, $log) {
        $log = str_replace('[', '*[', $log);
        $log = str_replace('\\r', '', $log);
        $log = str_replace('\\n', '', $log);
        $pirepdata = array(
            'user_id' => $dbid,
            'code' => $code,
            'flightnum' => $flightnumber,
            'depicao' => $departureicao,
            'arricao' => $arrivalicao,
            'route' => $route,
            'aircraft' => $aircraft,
            'legacyroute' => $routeid,
            'legacybid' => $bidid,
            'load' => $load,
            'flighttime' => $flighttime,
            'landingrate' => $landingrate,
            'submitdate' => date('Y-m-d H:i:s'),
            'comment' => $comments,
            'fuelused' => $fuelused,
            'source' => 'smartCARS',
            'log' => $log
        );

        VAOS_Flights::fileReport($pirepdata);

        return true;
    }
    private static function getProperFlightNum($flightnum, $userid)
    {
        if ($flightnum == '') {
            return false;
        }

        $ret       = [];
        $flightnum = strtoupper($flightnum);
        $airlines  = Airline::all();

        foreach ($airlines as $a) {
            $a->icao = strtoupper($a->icao);

            if (strpos($flightnum, $a->icao) === false) {
                continue;
            }

            $ret['icao']      = $a->icao;
            $ret['flightnum'] = str_ireplace($a->icao, '', $flightnum);

            // ok now that we deduced that, let's find the bid.
            //dd($userid);
            return Flight::where(['user_id' => $userid, 'airline_id' => $a->id, 'flightnum' => $ret['flightnum']])->where('state', '<=', '1')->first();
        }

        // Invalid flight number
        $ret['code']      = '';
        $ret['flightnum'] = $flightnum;

        return Flight::where(['user_id' => $userid, 'flightnum' => $ret['flightnum']])->where('state', '<=', '1')->first();
    }
}