<?php

namespace Drupal\farm_cfp;

class Constants {
  /**
   * The calculation log type for GHG calculations.
   */
  const GHG_CALCULATION = 'cfp_ghg';

  /**
   * The unit for greenhouse gas emissions in kilograms of CO2 equivalent.
   */
  const GHG_UNIT_KGCO2E = 'kgCO2e';

  /**
   * The basic operation mode.
   */
  const OPERATION_MODE_BASIC = 'basic';

  /**
   * The full operation mode.
   */
  const OPERATION_MODE_FULL = 'full';

  const SCHEMA_IGNORE = [
    'fertiliser',
    'pesticide',
    'irrigation',
    'fuelEnergy',
    'transport',
    'wasteWater',
    'machinery',
    'SOC',
    'nonCropEstimated',
    'nonCropMeasured',
    'landUseChangeBiomass',
    'refrigerants',
    'processing',
    'storage',
    ];
}
