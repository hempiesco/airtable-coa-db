# Hempie's COA Airtable Sync

This script syncs inventory from Square to Airtable for COA management. It maintains the same filtering logic as the original WordPress plugin but uses Airtable as the destination instead.

## Features

- Syncs products from Square to Airtable
- Filters out excluded categories
- Handles product variations
- Updates existing records or creates new ones
- Maintains sync status and logging
- Supports test mode for development

## Requirements

- PHP 8.1 or higher
- cURL extension
- Square API access token
- Airtable API key and base ID

## Local Development Setup

1. Clone this repository
2. Copy `.env.example` to `.env` and fill in your credentials:
   ```bash
   cp .env.example .env
   ```
3. Install dependencies:
   ```bash
   composer install
   ```
4. Run the script:
   ```bash
   # Normal mode
   php hempies-coa-airtable.php

   # Test mode (processes only 5 items)
   php hempies-coa-airtable.php --test
   ```

## Deployment to Render

1. Create a new Web Service on Render
2. Connect your GitHub repository
3. Configure the following settings:
   - Name: `hempies-coa-sync`
   - Environment: `PHP`
   - Build Command: `composer install`
   - Start Command: `php -S 0.0.0.0:$PORT`
4. Add the following environment variables in Render's dashboard:
   - `SQUARE_ACCESS_TOKEN`
   - `AIRTABLE_API_KEY`
   - `AIRTABLE_BASE_ID`
   - `AIRTABLE_TABLE_NAME` (defaults to "Products")
   - `PHP_VERSION` (set to "8.1")

## Airtable Setup

1. Create a new base in Airtable
2. Create a table named "Products" (or your preferred name)
3. Add the following fields:
   - SKU (Single line text)
   - Name (Single line text)
   - Status (Single select)
   - Quantity (Number)
   - Category (Single line text)
   - Created (DateTime)
   - Last Updated (DateTime)

## Status Values

The script uses the following status values in Airtable:

- Active: Product is in stock and available
- Out of Stock: Product has zero quantity
- Archived: Product is archived in Square
- Excluded: Product is in an excluded category

## Logging

The script logs all operations to both:
1. The console output
2. The PHP error log

## Error Handling

The script includes error handling for:
- Missing API credentials
- API request failures
- Invalid responses
- Network issues

## Contributing

Feel free to submit issues and enhancement requests! 