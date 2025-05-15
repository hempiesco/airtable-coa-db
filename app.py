from flask import Flask, render_template_string
import threading
import subprocess
import sys
import signal
import os

app = Flask(__name__)

# Global variable to track the sync process
current_sync_process = None

def run_worker():
    global current_sync_process
    try:
        current_sync_process = subprocess.Popen([sys.executable, 'airtable-coa.py'])
        current_sync_process.wait()
    except subprocess.CalledProcessError as e:
        print(f"Worker process failed with error: {e}")
    finally:
        current_sync_process = None

@app.route('/')
def home():
    return "Square to Airtable Sync Service is running"

@app.route('/sync')
def trigger_sync():
    global current_sync_process
    
    # HTML template with sync status and cancel button
    html_template = """
    <!DOCTYPE html>
    <html>
    <head>
        <title>Sync Status</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .button { 
                padding: 10px 20px; 
                background-color: #4CAF50; 
                color: white; 
                border: none; 
                border-radius: 4px; 
                cursor: pointer; 
                margin: 5px;
            }
            .cancel-button { 
                background-color: #f44336; 
            }
            .status { margin: 20px 0; }
        </style>
    </head>
    <body>
        <h1>Sync Status</h1>
        <div class="status">
            {% if is_syncing %}
                <p>Sync is currently running...</p>
                <form action="/cancel" method="post">
                    <button type="submit" class="button cancel-button">Cancel Sync</button>
                </form>
            {% else %}
                <p>No sync is currently running.</p>
                <form action="/sync" method="post">
                    <button type="submit" class="button">Start Sync</button>
                </form>
            {% endif %}
        </div>
    </body>
    </html>
    """
    
    return render_template_string(html_template, is_syncing=current_sync_process is not None)

@app.route('/sync', methods=['POST'])
def start_sync():
    if current_sync_process is None:
        thread = threading.Thread(target=run_worker)
        thread.start()
    return trigger_sync()

@app.route('/cancel', methods=['POST'])
def cancel_sync():
    global current_sync_process
    if current_sync_process is not None:
        try:
            # Send SIGTERM to the process
            os.kill(current_sync_process.pid, signal.SIGTERM)
            current_sync_process = None
        except Exception as e:
            print(f"Error canceling sync: {e}")
    return trigger_sync()

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=10000) 