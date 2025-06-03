import os
import time
import requests
import json
from datetime import datetime, timedelta
from pyairtable import Api
import logging
import schedule
import threading
import time
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

# Configuration from environment variables
SQUARE_ACCESS_TOKEN = os.environ.get('SQUARE_ACCESS_TOKEN')
SQUARE_LOCATION_ID = os.environ.get('SQUARE_LOCATION_ID', 'LXNA062VNG2T2')
AIRTABLE_API_KEY = os.environ.get('AIRTABLE_API_KEY')
AIRTABLE_BASE_ID = os.environ.get('AIRTABLE_BASE_ID')
AIRTABLE_TABLE_NAME = os.environ.get('AIRTABLE_TABLE_NAME', 'Products')
AIRTABLE_VENDOR_TABLE = os.environ.get('AIRTABLE_VENDOR_TABLE', 'Vendors')
NOTIFICATION_EMAIL = os.environ.get('NOTIFICATION_EMAIL')
SMTP_SERVER = os.environ.get('SMTP_SERVER', 'smtp.gmail.com')
SMTP_PORT = int(os.environ.get('SMTP_PORT', '587'))
SMTP_USERNAME = os.environ.get('SMTP_USERNAME')
SMTP_PASSWORD = os.environ.get('SMTP_PASSWORD')

# Categories to exclude
EXCLUDED_CATEGORIES = os.environ.get('EXCLUDED_CATEGORIES', 'Pet Products,Accessories,Crystals,Apparel,Party').split(',')

# Square API base URL
SQUARE_BASE_URL = 'https://connect.squareup.com/v2'

# Set up logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler("coa_sync.log"),
        logging.StreamHandler()
    ]
)
logger = logging.getLogger("COA_Sync")

# Initialize sync stats
stats = {
    'processed': 0,
    'created': 0,
    'updated': 0,
    'skipped': 0,
    'removed': 0,
    'total': 0
}

def send_notification(subject, message):
    """Send email notification"""
    if not all([NOTIFICATION_EMAIL, SMTP_USERNAME, SMTP_PASSWORD]):
        logger.warning("Email notification settings not configured")
        return

    try:
        msg = MIMEMultipart()
        msg['From'] = SMTP_USERNAME
        msg['To'] = NOTIFICATION_EMAIL
        msg['Subject'] = subject

        msg.attach(MIMEText(message, 'plain'))

        server = smtplib.SMTP(SMTP_SERVER, SMTP_PORT)
        server.starttls()
        server.login(SMTP_USERNAME, SMTP_PASSWORD)
        server.send_message(msg)
        server.quit()
        
        logger.info(f"Notification sent: {subject}")
    except Exception as e:
        logger.error(f"Failed to send notification: {str(e)}")

def fetch_square_categories():
    """Fetch all categories from Square API"""
    logger.info("Fetching categories from Square API...")
    
    categories = {}
    cursor = None
    
    while True:
        endpoint = f"{SQUARE_BASE_URL}/catalog/list?types=CATEGORY"
        if cursor:
            endpoint += f"&cursor={cursor}"
            
        headers = {
            'Square-Version': '2023-09-25',
            'Authorization': f'Bearer {SQUARE_ACCESS_TOKEN}',
            'Content-Type': 'application/json'
        }
        
        try:
            response = requests.get(endpoint, headers=headers)
            response.raise_for_status()
            data = response.json()
            
            if not data.get('objects'):
                break
                
            for obj in data.get('objects', []):
                if obj['type'] == 'CATEGORY' and 'id' in obj and 'name' in obj['category_data']:
                    categories[obj['id']] = obj['category_data']['name']
            
            cursor = data.get('cursor')
            if not cursor:
                break
                
        except Exception as e:
            logger.error(f"Error fetching categories: {str(e)}")
            break
    
    logger.info(f"Fetched {len(categories)} categories from Square")
    return categories

def is_excluded_category(category_id, category_name, category_map):
    """Check if a category is in the excluded list"""
    # If category is empty or None, it's not excluded
    if not category_name:
        return False
        
    # Log the category being checked
    logger.info(f"Checking category: '{category_name}' against excluded list: {EXCLUDED_CATEGORIES}")
    
    # Check by ID
    if category_id in EXCLUDED_CATEGORIES:
        logger.info(f"Category excluded by ID: {category_id}")
        return True
        
    # Check by name (exact match)
    if category_name in EXCLUDED_CATEGORIES:
        logger.info(f"Category excluded by exact name match: {category_name}")
        return True
        
    # Check case insensitive
    category_name_lower = category_name.lower()
    for excluded in EXCLUDED_CATEGORIES:
        excluded_lower = excluded.lower()
        if excluded_lower == category_name_lower:
            logger.info(f"Category excluded by case-insensitive name match: {category_name}")
            return True
        # Also check if the category name contains any of the excluded terms
        if excluded_lower in category_name_lower:
            logger.info(f"Category excluded by partial name match: {category_name} contains {excluded}")
            return True
    
    logger.info(f"Category not excluded: {category_name}")
    return False

def get_inventory_counts(catalog_item_id):
    """Get inventory counts for an item across all locations"""
    endpoint = f"{SQUARE_BASE_URL}/inventory/counts/batch-retrieve"
    
    headers = {
        'Square-Version': '2023-09-25',
        'Authorization': f'Bearer {SQUARE_ACCESS_TOKEN}',
        'Content-Type': 'application/json'
    }
    
    body = {
        'catalog_object_ids': [catalog_item_id],
        'location_ids': [SQUARE_LOCATION_ID]
    }
    
    try:
        response = requests.post(endpoint, headers=headers, json=body)
        response.raise_for_status()
        data = response.json()
        
        return data.get('counts', [])
    except Exception as e:
        logger.error(f"Error fetching inventory: {str(e)}")
        return []

def has_stock(inventory_counts):
    """Check if item has stock in any location"""
    if not inventory_counts:
        return False
    
    for count in inventory_counts:
        try:
            quantity = float(count.get('quantity', 0))
            if quantity > 0:
                return True
        except (ValueError, TypeError):
            logger.warning(f"Invalid quantity value: {count.get('quantity')}")
            continue
    
    return False

def fetch_square_items():
    """Fetch all items from Square API"""
    logger.info("Fetching items from Square API...")
    
    items = []
    cursor = None
    category_map = fetch_square_categories()
    
    # Log all categories for debugging
    logger.info("Available categories from Square:")
    for cat_id, cat_name in category_map.items():
        logger.info(f"Category ID: {cat_id}, Name: {cat_name}")
    
    while True:
        endpoint = f"{SQUARE_BASE_URL}/catalog/list?types=ITEM"
        if cursor:
            endpoint += f"&cursor={cursor}"
            
        headers = {
            'Square-Version': '2023-09-25',
            'Authorization': f'Bearer {SQUARE_ACCESS_TOKEN}',
            'Content-Type': 'application/json'
        }
        
        try:
            response = requests.get(endpoint, headers=headers)
            response.raise_for_status()
            data = response.json()
            
            if not data.get('objects'):
                break
                
            for item in data.get('objects', []):
                if item['type'] != 'ITEM':
                    continue
                    
                # Skip deleted items
                if item.get('is_deleted', False):
                    continue
                    
                # Get item data
                item_data = item.get('item_data', {})
                
                # Skip archived items
                if item_data.get('is_archived', False):
                    logger.info(f"Skipping archived item: {item_data.get('name', '')}")
                    continue
                
                item_name = item_data.get('name', '')
                
                # Get category information
                category_id = None
                category_name = None
                
                # Check categories array first
                if 'categories' in item_data:
                    for cat in item_data['categories']:
                        cat_id = cat.get('id')
                        if cat_id in category_map:
                            category_id = cat_id
                            category_name = category_map[cat_id]
                            break
                
                # If no category found in categories array, try category_id field
                if not category_id and 'category_id' in item_data:
                    category_id = item_data['category_id']
                    category_name = category_map.get(category_id, '')
                
                # Skip excluded categories
                if is_excluded_category(category_id, category_name, category_map):
                    logger.info(f"Skipping item {item_name} - in excluded category: {category_name}")
                    continue
                
                # Process variations
                if 'variations' in item_data and item_data['variations']:
                    for variation in item_data['variations']:
                        variation_data = variation.get('item_variation_data', {})
                        variation_id = variation.get('id')
                        variation_name = variation_data.get('name', '')
                        
                        # Get vendor ID from item_variation_vendor_infos
                        vendor_id = None
                        if 'item_variation_vendor_infos' in variation_data:
                            for vendor_info in variation_data['item_variation_vendor_infos']:
                                if not vendor_info.get('is_deleted', False):
                                    vendor_data = vendor_info.get('item_variation_vendor_info_data', {})
                                    vendor_id = vendor_data.get('vendor_id')
                                    if vendor_id:
                                        break
                        
                        # Check inventory for this variation
                        inventory_counts = get_inventory_counts(variation_id)
                        
                        # Check if item is out of stock at all locations
                        all_locations_out_of_stock = True
                        for count in inventory_counts:
                            try:
                                quantity = float(count.get('quantity', 0))
                                if quantity > 0:
                                    all_locations_out_of_stock = False
                                    break
                            except (ValueError, TypeError):
                                logger.warning(f"Invalid quantity value: {count.get('quantity')}")
                                continue
                        
                        if all_locations_out_of_stock:
                            logger.info(f"Skipping variation {item_name} - {variation_name} - out of stock at all locations")
                            continue
                            
                        # Variation has stock, add to list
                        full_name = item_name
                        if variation_name and variation_name != item_name:
                            full_name = f"{item_name} - {variation_name}"
                            
                        items.append({
                            'id': variation_id,
                            'name': full_name,
                            'parent_name': item_name,
                            'variation_name': variation_name,
                            'category_id': category_id,
                            'category_name': category_name,
                            'quantity': 1,  # We know it has stock but not the exact quantity
                            'sku': variation_data.get('sku', ''),
                            'vendor': vendor_id
                        })
                else:
                    # Simple product without variations
                    inventory_counts = get_inventory_counts(item.get('id'))
                    
                    # Check if item is out of stock at all locations
                    all_locations_out_of_stock = True
                    for count in inventory_counts:
                        try:
                            quantity = float(count.get('quantity', 0))
                            if quantity > 0:
                                all_locations_out_of_stock = False
                                break
                        except (ValueError, TypeError):
                            logger.warning(f"Invalid quantity value: {count.get('quantity')}")
                            continue
                    
                    if all_locations_out_of_stock:
                        logger.info(f"Skipping item {item_name} - out of stock at all locations")
                        continue
                        
                    items.append({
                        'id': item.get('id'),
                        'name': item_name,
                        'parent_name': item_name,
                        'variation_name': '',
                        'category_id': category_id,
                        'category_name': category_name,
                        'quantity': 1,  # We know it has stock but not the exact quantity
                        'sku': item_data.get('sku', ''),
                        'vendor': None  # Simple items don't have vendor info in this structure
                    })
            
            cursor = data.get('cursor')
            if not cursor:
                break
                
        except Exception as e:
            logger.error(f"Error fetching items: {str(e)}")
            break
    
    logger.info(f"Fetched {len(items)} items with stock from Square")
    return items

def get_existing_airtable_products():
    """Get all existing products from Airtable"""
    logger.info("Fetching existing products from Airtable...")
    
    existing_products = {}
    
    try:
        table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_TABLE_NAME)
        records = table.all()
        
        for record in records:
            product_id = record['fields'].get('ProductID')
            if product_id:
                existing_products[product_id] = record
                
        logger.info(f"Found {len(existing_products)} existing products in Airtable")
        return existing_products
    except Exception as e:
        logger.error(f"Error fetching Airtable products: {str(e)}")
        return {}

def get_vendor_record_id(vendor_id):
    """Get the Airtable record ID for a Square vendor ID"""
    try:
        table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_VENDOR_TABLE)
        records = table.all(formula=f"{{VendorID}}='{vendor_id}'")
        if records:
            return records[0]['id']
        return None
    except Exception as e:
        logger.error(f"Error getting vendor record ID: {str(e)}")
        return None

def sync_square_to_airtable():
    """Main function to sync Square products to Airtable"""
    logger.info("Starting Square to Airtable sync...")
    
    # Get items from Square
    items = fetch_square_items()
    stats['total'] = len(items)
    
    # Get existing products from Airtable
    existing_products = get_existing_airtable_products()
    
    # Track product IDs to keep
    products_to_keep = set()
    
    # Process each item
    for item in items:
        stats['processed'] += 1
        
        product_id = item['id']
        name = item['name']
        category_name = item['category_name']
        vendor_id = item['vendor']  # Get the vendor ID
        
        # Skip if category is in excluded list (but allow empty categories)
        if category_name and is_excluded_category(None, category_name, None):
            logger.info(f"Skipping product {name} - category {category_name} is excluded")
            stats['skipped'] += 1
            continue
        
        # Record should be kept
        products_to_keep.add(product_id)
        
        # Prepare Airtable record data
        record_data = {
            'ProductID': product_id,
            'Product Name': name,
            'Current Quantity': item['quantity'],
            'Item Data Ecom Available': True,
            'Present At All Locations': True,
            'Last Updated': datetime.now().strftime('%m/%d/%Y %I:%M %p'),
            'SKU': item['sku'],
            'Vendor': str(vendor_id) if vendor_id else ''  # Send vendor ID as a simple string
        }
        
        # Add category if it exists
        if category_name and category_name.strip():
            record_data['Category'] = category_name.strip()
        
        # Check if product already exists
        if product_id in existing_products:
            # Update existing record
            record = existing_products[product_id]
            try:
                table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_TABLE_NAME)
                table.update(record['id'], record_data)
                logger.info(f"Updated product: {name}")
                stats['updated'] += 1
            except Exception as e:
                logger.error(f"Error updating product {name}: {str(e)}")
        else:
            # Create new record
            try:
                table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_TABLE_NAME)
                table.create(record_data)
                logger.info(f"Created product: {name}")
                stats['created'] += 1
            except Exception as e:
                logger.error(f"Error creating product {name}: {str(e)}")
    
    # Remove products that no longer have stock or were excluded
    for product_id, record in existing_products.items():
        if product_id not in products_to_keep:
            try:
                table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_TABLE_NAME)
                table.delete(record['id'])
                logger.info(f"Removed product: {record['fields'].get('Product Name', 'Unknown')}")
                stats['removed'] += 1
            except Exception as e:
                logger.error(f"Error removing product {record['fields'].get('Product Name', 'Unknown')}: {str(e)}")
    
    # Log final stats
    logger.info(f"Sync completed. Stats: {json.dumps(stats)}")

def fetch_square_vendors():
    """Fetch all vendors from Square API"""
    logger.info("Fetching vendors from Square API...")
    
    vendors = []
    cursor = None
    
    while True:
        endpoint = f"{SQUARE_BASE_URL}/vendors/search"
        
        headers = {
            'Square-Version': '2023-09-25',
            'Authorization': f'Bearer {SQUARE_ACCESS_TOKEN}',
            'Content-Type': 'application/json'
        }
        
        # Prepare request body with filter for active vendors
        body = {
            "filter": {
                "status": ["ACTIVE"]
            }
        }
        
        # Add cursor if we have one
        if cursor:
            body["cursor"] = cursor
        
        try:
            response = requests.post(endpoint, headers=headers, json=body)
            response.raise_for_status()
            data = response.json()
            
            if not data.get('vendors'):
                break
                
            for vendor in data.get('vendors', []):
                # Get primary contact info (first non-removed contact)
                contact_info = None
                for contact in vendor.get('contacts', []):
                    if not contact.get('removed', False):
                        contact_info = contact
                        break
                
                # Extract address components
                address = vendor.get('address', {})
                address_line_1 = address.get('address_line_1', '')
                address_line_2 = address.get('address_line_2', '')
                city = address.get('locality', '')
                state = address.get('administrative_district_level_1', '')
                postal_code = address.get('postal_code', '')
                
                # Combine address components
                full_address = f"{address_line_1}"
                if address_line_2:
                    full_address += f", {address_line_2}"
                if city:
                    full_address += f", {city}"
                if state:
                    full_address += f", {state}"
                if postal_code:
                    full_address += f" {postal_code}"
                
                vendors.append({
                    'id': vendor.get('id'),
                    'name': vendor.get('name', ''),
                    'phone': contact_info.get('phone_number', '') if contact_info else '',
                    'email': contact_info.get('email_address', '') if contact_info else '',
                    'contact_name': contact_info.get('name', '') if contact_info else '',
                    'address': full_address.strip()
                })
            
            cursor = data.get('cursor')
            if not cursor:
                break
                
        except Exception as e:
            logger.error(f"Error fetching vendors: {str(e)}")
            break
    
    logger.info(f"Fetched {len(vendors)} vendors from Square")
    return vendors

def get_existing_airtable_vendors():
    """Get all existing vendors from Airtable"""
    logger.info("Fetching existing vendors from Airtable...")
    
    existing_vendors = {}
    
    try:
        table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_VENDOR_TABLE)
        records = table.all()
        
        for record in records:
            vendor_id = record['fields'].get('VendorID')
            if vendor_id:
                existing_vendors[vendor_id] = record
                
        logger.info(f"Found {len(existing_vendors)} existing vendors in Airtable")
        return existing_vendors
    except Exception as e:
        logger.error(f"Error fetching Airtable vendors: {str(e)}")
        return {}

def sync_vendors_to_airtable():
    """Sync Square vendors to Airtable"""
    logger.info("Starting vendor sync...")
    
    # Get vendors from Square
    vendors = fetch_square_vendors()
    
    # Get existing vendors from Airtable
    existing_vendors = get_existing_airtable_vendors()
    
    # Track vendor IDs to keep
    vendors_to_keep = set()
    
    # Process each vendor
    for vendor in vendors:
        vendor_id = vendor['id']
        name = vendor['name']
        
        # Record should be kept
        vendors_to_keep.add(vendor_id)
        
        # Prepare Airtable record data
        record_data = {
            'VendorID': vendor_id,
            'Name': name,
            'Phone': vendor['phone'],
            'Email': vendor['email'],
            'Contact': vendor['contact_name'],
            'Last Synced': datetime.now().strftime('%m/%d/%Y %I:%M %p')
        }
        
        # Check if vendor already exists
        if vendor_id in existing_vendors:
            # Update existing record
            record = existing_vendors[vendor_id]
            try:
                table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_VENDOR_TABLE)
                table.update(record['id'], record_data)
                logger.info(f"Updated vendor: {name}")
            except Exception as e:
                logger.error(f"Error updating vendor {name}: {str(e)}")
        else:
            # Create new record
            try:
                table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_VENDOR_TABLE)
                table.create(record_data)
                logger.info(f"Created vendor: {name}")
            except Exception as e:
                logger.error(f"Error creating vendor {name}: {str(e)}")
    
    # Remove vendors that no longer exist in Square
    for vendor_id, record in existing_vendors.items():
        if vendor_id not in vendors_to_keep:
            try:
                table = Api(AIRTABLE_API_KEY).table(AIRTABLE_BASE_ID, AIRTABLE_VENDOR_TABLE)
                table.delete(record['id'])
                logger.info(f"Removed vendor: {record['fields'].get('Name', 'Unknown')}")
            except Exception as e:
                logger.error(f"Error removing vendor {record['fields'].get('Name', 'Unknown')}: {str(e)}")
    
    logger.info("Vendor sync completed")

def run_sync():
    """Run the complete sync process"""
    start_time = datetime.now()
    logger.info("Starting automated sync process...")
    send_notification(
        "COA Sync Started",
        f"Automated sync process started at {start_time.strftime('%Y-%m-%d %H:%M:%S')}"
    )
    
    try:
        sync_vendors_to_airtable()  # Sync vendors first
        sync_square_to_airtable()   # Then sync products
        
        end_time = datetime.now()
        duration = end_time - start_time
        
        # Prepare stats message
        stats_message = f"""
Sync completed successfully!

Duration: {duration}
Stats:
- Total items processed: {stats['total']}
- Created: {stats['created']}
- Updated: {stats['updated']}
- Skipped: {stats['skipped']}
- Removed: {stats['removed']}

Started at: {start_time.strftime('%Y-%m-%d %H:%M:%S')}
Completed at: {end_time.strftime('%Y-%m-%d %H:%M:%S')}
"""
        send_notification("COA Sync Completed", stats_message)
        logger.info("Automated sync completed successfully")
    except Exception as e:
        error_message = f"""
Error during automated sync:

Error: {str(e)}
Time: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
"""
        send_notification("COA Sync Error", error_message)
        logger.error(f"Error during automated sync: {str(e)}")

def run_scheduler():
    """Run the scheduler in a separate thread"""
    while True:
        schedule.run_pending()
        time.sleep(60)  # Check every minute

def setup_scheduler():
    """Set up the weekly schedule"""
    # Schedule the sync to run every Monday at 1:00 AM
    schedule.every().monday.at("01:00").do(run_sync)
    
    # Start the scheduler in a separate thread
    scheduler_thread = threading.Thread(target=run_scheduler, daemon=True)
    scheduler_thread.start()
    
    next_run = schedule.next_run()
    message = f"""
Scheduler started successfully!

Next scheduled run: {next_run.strftime('%Y-%m-%d %H:%M:%S')}
Sync will run every Monday at 1:00 AM
"""
    send_notification("COA Sync Scheduler Started", message)
    logger.info("Scheduler started - sync will run every Monday at 1:00 AM")

if __name__ == "__main__":
    if not SQUARE_ACCESS_TOKEN:
        logger.error("Square API token is not configured")
        exit(1)
        
    if not AIRTABLE_API_KEY or not AIRTABLE_BASE_ID:
        logger.error("Airtable credentials are not configured")
        exit(1)
    
    # Set up the scheduler for automated weekly runs
    setup_scheduler()
    
    # Run an initial sync
    logger.info("Running initial sync...")
    run_sync()
    
    # Keep the main thread alive
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        logger.info("Shutting down...")