from flask import Flask
import threading
import subprocess
import sys

app = Flask(__name__)

def run_worker():
    try:
        subprocess.run([sys.executable, 'airtable-coa.py'], check=True)
    except subprocess.CalledProcessError as e:
        print(f"Worker process failed with error: {e}")

@app.route('/')
def home():
    return "Square to Airtable Sync Service is running"

@app.route('/sync')
def trigger_sync():
    thread = threading.Thread(target=run_worker)
    thread.start()
    return "Sync process started"

if __name__ == '__main__':
    # Start the worker process when the app starts
    thread = threading.Thread(target=run_worker)
    thread.start()
    app.run(host='0.0.0.0', port=10000) 