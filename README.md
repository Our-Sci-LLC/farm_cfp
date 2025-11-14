# farmOS CFP

farmOS integration with [Cool Farm Platform](https://coolfarm.org/).

The Cool Farm Platform enables you to measure both carbon emissions and carbon sequestration across agricultural systems.
This module provides integration between farmOS and the Cool Farm Platform by allowing users to run Cool Farm assessment calculations
based on data stored in farmOS.

Cool Farm assessments can be initiated from within farmOS, and the results of these assessments are stored back in farmOS as
Calculation logs.

## Requirements

This module requires an account with the Cool Farm Platform and the following farmOS modules:
- [farmOS Integrations](https://www.drupal.org/project/farm_integration)
- [farmOS Calculations](https://www.drupal.org/project/farm_calculation)

## Installation

Install as you would normally install a contributed Drupal module.
See: https://www.drupal.org/node/895232 for further information.

## Configuration
- Obtain API credentials from Cool Farm Platform.
- Configure Cool Farm Platform API key at /farm/settings/cfp
- Select the operation mode, basic or experimental.
- Configure default settings for your farm:
  - CFP Pathway (Annuals, Perennials, etc)
  - Country
  - Climate type
  - Annual average temperature

## Usage
- Navigate to the Cool Farm Platform overview page at /integrations/cfp
- Click "Add Assessment" to create a new Cool Farm assessment.
