from flask import Flask, render_template_string, redirect, url_for
import threading
import subprocess
import sys
import signal
import os
import psutil

app = Flask(__name__)

# Global variable to track the sync process
current_sync_process = None
sync_thread = None

def run_worker():
    global current_sync_process
    try:
        # Create a new process group
        current_sync_process = subprocess.Popen(
            [sys.executable, 'airtable-coa.py'],
            preexec_fn=os.setsid  # This creates a new process group
        )
        current_sync_process.wait()
    except subprocess.CalledProcessError as e:
        print(f"Worker process failed with error: {e}")
    finally:
        current_sync_process = None

def is_process_running(pid):
    try:
        process = psutil.Process(pid)
        return process.is_running()
    except (psutil.NoSuchProcess, psutil.AccessDenied):
        return False

@app.route('/')
def home():
    return "Square to Airtable Sync Service is running"

@app.route('/sync')
def trigger_sync():
    global current_sync_process
    
    # Check if process is actually running
    is_syncing = False
    if current_sync_process is not None:
        try:
            is_syncing = current_sync_process.poll() is None
        except:
            is_syncing = False
    
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
            .refresh-button {
                background-color: #2196F3;
            }
            .status { margin: 20px 0; }
        </style>
    </head>
    <body>
        <h1>Sync Status</h1>
        <div class="status">
            <p id="sync-status">
                {% if is_syncing %}
                    Sync is currently running...
                {% else %}
                    No sync is currently running.
                {% endif %}
            </p>
            {% if is_syncing %}
                <form action="/cancel" method="post">
                    <button type="submit" class="button cancel-button">Cancel Sync</button>
                </form>
            {% else %}
                <form action="/sync" method="post">
                    <button type="submit" class="button">Start Sync</button>
                </form>
            {% endif %}
            <form action="/sync" method="get" style="margin-top: 10px;">
                <button type="submit" class="button refresh-button">Refresh Status</button>
            </form>
        </div>
    </body>
    </html>
    """
    
    return render_template_string(html_template, is_syncing=is_syncing)

@app.route('/sync', methods=['POST'])
def start_sync():
    global current_sync_process, sync_thread
    if current_sync_process is None or current_sync_process.poll() is not None:
        sync_thread = threading.Thread(target=run_worker)
        sync_thread.start()
    return redirect(url_for('trigger_sync'))

@app.route('/cancel', methods=['POST'])
def cancel_sync():
    global current_sync_process
    if current_sync_process is not None:
        try:
            # Kill the entire process group
            os.killpg(os.getpgid(current_sync_process.pid), signal.SIGTERM)
            current_sync_process = None
        except Exception as e:
            print(f"Error canceling sync: {e}")
    return redirect(url_for('trigger_sync'))

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=10000) 