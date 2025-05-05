import subprocess
import sys

def run_worker():
    try:
        subprocess.run([sys.executable, 'airtable-coa.py'], check=True)
    except subprocess.CalledProcessError as e:
        print(f"Worker process failed with error: {e}")
        sys.exit(1)

if __name__ == "__main__":
    run_worker() 