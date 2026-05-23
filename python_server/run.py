"""
Uvicorn launcher for the ZKTeco Attendance Server.
Run this script to start the server on port 8015.

Usage:
    python run.py
"""

import uvicorn
from config import SERVER_HOST, SERVER_PORT, LOG_LEVEL

if __name__ == "__main__":
    print(f"Starting ZKTeco Attendance Server on {SERVER_HOST}:{SERVER_PORT}")
    print(f"Device URL: http://YOUR_PUBLIC_IP:{SERVER_PORT}/iclock")
    print("-" * 60)

    uvicorn.run(
        "main:app",
        host=SERVER_HOST,
        port=SERVER_PORT,
        log_level=LOG_LEVEL.lower(),
        reload=False,
        access_log=True,
    )
