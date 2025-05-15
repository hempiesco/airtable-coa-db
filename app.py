from flask import Flask, render_template
import threading
import subprocess
import sys
from datetime import datetime

app = Flask(__name__)

def run_sync():
    try:
        subprocess.run([sys.executable, 'airtable-coa.py'], check=True)
        return "Sync completed successfully"
    except subprocess.CalledProcessError as e:
        return f"Sync failed: {str(e)}"
    except Exception as e:
        return f"Unexpected error: {str(e)}"

@app.route('/')
def home():
    result = run_sync()
    return render_template('index.html', status=result)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=10000) 