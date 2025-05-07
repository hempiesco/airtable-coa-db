from flask import Flask, render_template, jsonify
import threading
import subprocess
import sys
import time
from datetime import datetime
import signal
import os

app = Flask(__name__)

# Global variables for sync control
sync_status = {
    'is_running': False,
    'is_paused': False,
    'last_sync': None,
    'current_operation': None,
    'error': None
}

# Global variable to store the subprocess
current_process = None

def run_sync():
    global sync_status, current_process
    try:
        sync_status['is_running'] = True
        sync_status['is_paused'] = False
        sync_status['error'] = None
        
        # Run vendor sync
        sync_status['current_operation'] = 'Syncing vendors...'
        
        # Create the subprocess
        current_process = subprocess.Popen(
            [sys.executable, 'airtable-coa.py'],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )
        
        # Monitor the process
        while current_process.poll() is None:
            if sync_status['is_paused']:
                # Send SIGSTOP to pause the process
                os.kill(current_process.pid, signal.SIGSTOP)
                time.sleep(0.1)  # Small delay to prevent CPU spinning
            else:
                # If it was paused, resume it
                try:
                    os.kill(current_process.pid, signal.SIGCONT)
                except ProcessLookupError:
                    pass
            
            # Check if we should stop
            if not sync_status['is_running']:
                current_process.terminate()
                current_process.wait()
                break
        
        # Get the output
        stdout, stderr = current_process.communicate()
        
        if current_process.returncode == 0:
            sync_status['last_sync'] = datetime.now().strftime('%m/%d/%Y %I:%M %p')
            sync_status['current_operation'] = 'Sync completed successfully'
        else:
            sync_status['error'] = f"Sync failed with return code {current_process.returncode}"
            if stderr:
                sync_status['error'] += f": {stderr}"
            sync_status['current_operation'] = 'Sync failed'
            
    except Exception as e:
        sync_status['error'] = f"Unexpected error: {str(e)}"
        sync_status['current_operation'] = 'Sync failed'
    finally:
        sync_status['is_running'] = False
        sync_status['is_paused'] = False
        current_process = None

@app.route('/')
def home():
    return render_template('index.html', status=sync_status)

@app.route('/sync/start')
def start_sync():
    if not sync_status['is_running']:
        thread = threading.Thread(target=run_sync)
        thread.start()
        return jsonify({'status': 'started'})
    return jsonify({'status': 'already_running'})

@app.route('/sync/stop')
def stop_sync():
    if sync_status['is_running']:
        sync_status['is_running'] = False
        sync_status['is_paused'] = False
        if current_process:
            try:
                current_process.terminate()
                current_process.wait(timeout=5)  # Wait up to 5 seconds for process to terminate
            except subprocess.TimeoutExpired:
                current_process.kill()  # Force kill if it doesn't terminate
        sync_status['current_operation'] = 'Sync stopped by user'
        return jsonify({'status': 'stopped'})
    return jsonify({'status': 'not_running'})

@app.route('/sync/pause')
def pause_sync():
    if sync_status['is_running'] and not sync_status['is_paused']:
        sync_status['is_paused'] = True
        sync_status['current_operation'] = 'Sync paused'
        return jsonify({'status': 'paused'})
    return jsonify({'status': 'cannot_pause'})

@app.route('/sync/resume')
def resume_sync():
    if sync_status['is_running'] and sync_status['is_paused']:
        sync_status['is_paused'] = False
        sync_status['current_operation'] = 'Sync resumed'
        return jsonify({'status': 'resumed'})
    return jsonify({'status': 'cannot_resume'})

@app.route('/sync/status')
def get_status():
    return jsonify(sync_status)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=10000) 