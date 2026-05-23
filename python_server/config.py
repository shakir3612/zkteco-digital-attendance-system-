"""
Configuration settings for the ZKTeco Attendance Server.
All settings can be overridden via environment variables or .env file.
"""

import os
from dotenv import load_dotenv

# Load .env file if it exists
load_dotenv()


# =============================================================================
# DATABASE SETTINGS
# =============================================================================
DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_USER = os.getenv("DB_USER", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")
DB_NAME = os.getenv("DB_NAME", "attendance_system")
DB_POOL_SIZE = int(os.getenv("DB_POOL_SIZE", "10"))
DB_POOL_RECYCLE = int(os.getenv("DB_POOL_RECYCLE", "3600"))

# =============================================================================
# SERVER SETTINGS
# =============================================================================
SERVER_HOST = os.getenv("SERVER_HOST", "0.0.0.0")
SERVER_PORT = int(os.getenv("SERVER_PORT", "8015"))
SERVER_TIMEZONE = os.getenv("SERVER_TIMEZONE", "Asia/Dhaka")

# =============================================================================
# DEVICE COMMUNICATION SETTINGS
# =============================================================================
# How often device polls (used for calculating online/offline status)
DEVICE_POLL_INTERVAL_SECONDS = int(os.getenv("DEVICE_POLL_INTERVAL", "30"))

# Thresholds for device status (in minutes)
DEVICE_IDLE_THRESHOLD_MINUTES = int(os.getenv("DEVICE_IDLE_THRESHOLD", "2"))
DEVICE_OFFLINE_THRESHOLD_MINUTES = int(os.getenv("DEVICE_OFFLINE_THRESHOLD", "10"))

# Rate limiting for device registration (max new registrations per IP per hour)
DEVICE_REG_RATE_LIMIT = int(os.getenv("DEVICE_REG_RATE_LIMIT", "10"))

# =============================================================================
# LOGGING
# =============================================================================
LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO")
LOG_FILE = os.getenv("LOG_FILE", "attendance_server.log")
