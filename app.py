from flask import Flask, render_template, jsonify
import threading
import subprocess
import sys
import time
from datetime import datetime

app = Flask(__name__)

# Global variables for sync control
sync_status = {
    'is_running': False,
    'is_paused': False,
    'last_sync': None,
    'current_operation': None,
    'error': None
}

def run_sync():
    global sync_status
    try:
        sync_status['is_running'] = True
        sync_status['is_paused'] = False
        sync_status['error'] = None
        
        # Run vendor sync
        sync_status['current_operation'] = 'Syncing vendors...'
        subprocess.run([sys.executable, 'airtable-coa.py'], check=True)
        
        # Update status
        sync_status['last_sync'] = datetime.now().strftime('%m/%d/%Y %I:%M %p')
        sync_status['current_operation'] = 'Sync completed successfully'
    except subprocess.CalledProcessError as e:
        sync_status['error'] = f"Sync failed: {str(e)}"
        sync_status['current_operation'] = 'Sync failed'
    except Exception as e:
        sync_status['error'] = f"Unexpected error: {str(e)}"
        sync_status['current_operation'] = 'Sync failed'
    finally:
        sync_status['is_running'] = False
        sync_status['is_paused'] = False

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