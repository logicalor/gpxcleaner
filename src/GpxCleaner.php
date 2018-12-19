<?php

namespace Logicalor;

use phpGPX\phpGPX;

class GpxCleaner 
{

    protected $file;
    protected $gpx;
    protected $config;

    public function __construct($path, $config = []) 
    {

        $this->gpx = new phpGPX();
        $this->file = $this->gpx->load($path);

        $this->config = [
            'distanceThreshold' => (isset($config['distanceThreshold'])) ? $config['distanceThreshold'] : 40,
            'angleThreshold' => (isset($config['angleThreshold'])) ? $config['angleThreshold'] : 90,
            'trimSteps' => (isset($config['trimSteps'])) ? $config['trimSteps'] : 2,
        ];

    }

    public function getFile() {
      return $this->file;
    }

    public function trim() 
    {

        foreach ($this->file->tracks as &$track) {

            foreach ($track->segments as &$segment) {
        
                $points = $segment->points;
                $runs = 1;
                $steps = $this->config['trimSteps'];

                while ($runs <= $steps) {
                    $points = $this->trimPoints($points, $steps);
                    $steps--;
                }
        
                $segment->points = $points;
        
            }
        
        }

    }

    private function trimPoints($points, $step) 
    {

        $distanceThreshold = $this->config['distanceThreshold'] / $step;
        $angleThreshold = $this->config['angleThreshold'];

        $points = array_map(function($index) use (&$points, $distanceThreshold, $angleThreshold) {
        
            if (property_exists($points[$index], 'discard') && $points[$index]->discard == true) {
                return $points[$index];
            }
    
            $haveCandidates = true;
            $candidates = [];
            $iterator = 1;
    
            while ($haveCandidates == true) {
    
                if (!isset($points[$index + $iterator])) {
                    $haveCandidates = false;
                }
                else {
                    $distance = $this->vincentyGreatCircleDistance(
                        $points[$index]->latitude,
                        $points[$index]->longitude,
                        $points[$index + $iterator]->latitude,
                        $points[$index + $iterator]->longitude
                    );
                    if ($distance >= $distanceThreshold || !isset($points[$index + $iterator + 1])) {
                        $haveCandidates = false;
                    }
                    else {
                        if (!(
                            property_exists($points[$index + $iterator], 'discard') && 
                            $points[$index + $iterator]->discard == true
                        )) {
                            $bearing = $this->getBearingBetweenPoints(
                                $points[$index]->latitude,
                                $points[$index]->longitude,
                                $points[$index + $iterator]->latitude,
                                $points[$index + $iterator]->longitude
                            );
                            $bearingToNext = $this->getBearingBetweenPoints(
                                $points[$index + $iterator]->latitude,
                                $points[$index + $iterator]->longitude,
                                $points[$index + $iterator + 1]->latitude,
                                $points[$index + $iterator + 1]->longitude
                            );
                            $angleDifference = abs($this->calculateDifferenceBetweenAngles($bearing, $bearingToNext));
                            $distanceToNext = $this->vincentyGreatCircleDistance(
                                $points[$index + $iterator]->latitude,
                                $points[$index + $iterator]->longitude,
                                $points[$index + $iterator + 1]->latitude,
                                $points[$index + $iterator + 1]->longitude
                            );
                            $intervalToNext = $points[$index + $iterator + 1]->time->format('U') - $points[$index + $iterator]->time->format('U');
                            $speedinkph = (($distanceToNext / 1000) / ($intervalToNext / 3600));
                            $candidates[] = [
                                'index' => $index + $iterator,
                                'distance' => $distance,
                                'angleDifference' => $angleDifference, 
                                'speed' => $speedinkph,
                            ];
                        }
                    }
                    $iterator++;
                }
            
            }
    
            if (count($candidates) > 0) {
                usort($candidates, function ($a, $b) use ($angleThreshold) {
                    $product_a = ($a['distance'] * $a['angleDifference']);
                    $product_b = ($b['distance'] * $b['angleDifference']);
                    if ($product_a == $product_b) {
                        return 0;
                    }
                    return ($product_a < $product_b) ? -1 : 1;
                });
                
                $chosen = null;
    
                if (count($candidates) > 1) {
                    $chosen = array_reduce(array_reverse($candidates), function($carry, $candidate) use ($angleThreshold) {
                        if ($candidate['angleDifference'] < $angleThreshold && $candidate['speed'] < 8) {
                            $carry = $candidate['index'];
                        }
                        return $carry;
                    }, $chosen);
                }
    
                if (!$chosen) {
                    $chosen = $candidates[0]['index'];
                }
        
                foreach ($candidates as $candidate) {
                    if ($candidate['index'] < $chosen) {
                        $points[$candidate['index']]->discard = true;
                    }
                }
            }
    
            return $points[$index];
        }, array_keys($points));
    
        $points = array_reduce($points, function($carry, $point) {
            if (property_exists($point, 'discard') && $point->discard == true) {
                return $carry;
            }
            $carry[] = $point;
            return $carry;
        }, []);
    
        return $points;

    }

    private function vincentyGreatCircleDistance($latitudeFrom, $longitudeFrom, $latitudeTo, 
        $longitudeTo, $earthRadius = 6371000) 
    {
        // convert from degrees to radians
        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);
      
        $lonDelta = $lonTo - $lonFrom;
        $a = pow(cos($latTo) * sin($lonDelta), 2) +
          pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
        $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);
      
        $angle = atan2(sqrt($a), $b);
        return $angle * $earthRadius;
    }

    private function getBearingBetweenPoints( $lat1, $lon1, $lat2, $lon2 ) 
    {
        return $this->getRhumbLineBearing( $lat1, $lon2, $lat2, $lon1 );
    }

    private function getRhumbLineBearing($lat1, $lon1, $lat2, $lon2) 
    {
        //difference in longitudinal coordinates
        $dLon = deg2rad($lon2) - deg2rad($lon1);
      
        //difference in the phi of latitudinal coordinates
        $dPhi = log(tan(deg2rad($lat2) / 2 + pi() / 4) / tan(deg2rad($lat1) / 2 + pi() / 4));
      
        //we need to recalculate $dLon if it is greater than pi
        if(abs($dLon) > pi()) {
            if($dLon > 0) {
                $dLon = (2 * pi() - $dLon) * -1;
            }
            else {
                $dLon = 2 * pi() + $dLon;
            }
        }
        //return the angle, normalized
        return (rad2deg(atan2($dLon, $dPhi)) + 360) % 360;
    }

    private function calculateDifferenceBetweenAngles($firstAngle, $secondAngle)
    {
        $difference = $secondAngle - $firstAngle;
        while ($difference < -180) {
            $difference += 360;
        }
        while ($difference > 180) {
            $difference -= 360;
        }
        return $difference;
    }

    public function saveGpx($path) 
    {
        $this->file->save($path, phpGPX::XML_FORMAT);
    }

}

