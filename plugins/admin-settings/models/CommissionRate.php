<?php

namespace Settings;
use \Model\Model;

defined('ROOT') or die("Direct script access denied");

class CommissionRate extends Model
{
    protected $table = 'commission_rates';
    protected $fillable = [
        'commission_type',
        'charge_type',
        'commission_rate',
        'starting_amount',
        'is_capped',
        'cap_value',
        'expected_value',
        'status',
        'created_by',
        'updated_by'
    ];

    /**
     * Get all active commission rates
     */
    public function getActive()
    {
        return $this->where(['status' => 1])->orderBy('commission_type', 'ASC')->get();
    }

    /**
     * Get commission rate by type
     */
    public function getByType($type)
    {
        return $this->first(['commission_type' => $type]);
    }

    /**
     * Calculate commission based on this rate
     */
    public function calculateCommission($amount)
    {
        $amount = (float)$amount;
        $startingAmount = (float)($this->starting_amount ?? 0);
        if ($amount < $startingAmount) {
            return 0.0;
        }

        if (strcasecmp((string)($this->charge_type ?? 'percentage'), 'flat') === 0) {
            $commission = (float)$this->commission_rate;
        } else {
        $commission = ($amount * $this->commission_rate) / 100;
        }
        
        // Apply cap if enabled
        if ($this->is_capped && $commission > $this->cap_value) {
            $commission = $this->cap_value;
        }
        
        return $commission;
    }

    /**
     * Calculate expected value based on commission
     */
    public function calculateExpectedValue($commission)
    {
        return ($commission * $this->expected_value) / 100;
    }
}
