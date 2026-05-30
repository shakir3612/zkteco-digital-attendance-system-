"""
ZKTeco Attendance Server - FastAPI Application Entry Point.
Handles device communication via the PUSH/ADMS (iclock) protocol.
"""

import logging
import sys
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from config import LOG_LEVEL, LOG_FILE, SERVER_TIMEZONE
from database import init_db_pool, close_db_pool
from routes.iclock import router as iclock_router
from routes.api import router as api_router

# =============================================================================
# LOGGING SETUP
# =============================================================================
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL.upper(), logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(LOG_FILE, encoding="utf-8"),
    ],
)
logger = logging.getLogger(__name__)


# =============================================================================
# APP LIFESPAN (startup / shutdown)
# =============================================================================
@asynccontextmanager
async def lifespan(app: FastAPI):
    """Manage app startup and shutdown events."""
    # STARTUP
    logger.info("=" * 60)
    logger.info("ZKTeco Attendance Server Starting...")
    logger.info(f"Timezone: {SERVER_TIMEZONE}")
    logger.info("=" * 60)

    # Initialize database pool
    await init_db_pool()
    logger.info("Database connection pool ready")

    # Start background workers
    import asyncio
    from workers.sync_worker import start_sync_worker, stop_sync_worker
    from workers.device_monitor import start_device_monitor, stop_device_monitor
    from workers.attendance_processor import start_attendance_worker, stop_attendance_worker
    from workers.time_sync_cron import start_time_sync_worker, stop_time_sync_worker

    sync_task = asyncio.create_task(start_sync_worker())
    monitor_task = asyncio.create_task(start_device_monitor())
    attendance_task = asyncio.create_task(start_attendance_worker())
    time_sync_task = asyncio.create_task(start_time_sync_worker())
    logger.info("Background workers started (sync + device monitor + attendance processor + time sync)")

    yield

    # SHUTDOWN
    logger.info("Shutting down...")
    await stop_sync_worker()
    await stop_device_monitor()
    await stop_attendance_worker()
    await stop_time_sync_worker()
    sync_task.cancel()
    monitor_task.cancel()
    attendance_task.cancel()
    time_sync_task.cancel()
    await close_db_pool()
    logger.info("Server stopped")


# =============================================================================
# CREATE APP
# =============================================================================
app = FastAPI(
    title="ZKTeco Attendance Server",
    description="Device communication server for ZKTeco SpeedFace V5L using PUSH/ADMS protocol",
    version="1.2.0",
    lifespan=lifespan,
)

# CORS middleware (allow PHP dashboard to call internal APIs)
app.add_middleware(
    CORSMiddleware,
    allow_origins=[
        "http://localhost",
        "http://127.0.0.1",
        "http://localhost:80",
        "http://127.0.0.1:80",
    ],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# =============================================================================
# REGISTER ROUTES
# =============================================================================
app.include_router(iclock_router)
app.include_router(api_router)


# =============================================================================
# HEALTH CHECK
# =============================================================================
@app.get("/")
async def root():
    """Server health check endpoint."""
    return {
        "status": "running",
        "service": "ZKTeco Attendance Server",
        "version": "1.2.0",
        "timezone": SERVER_TIMEZONE,
    }


@app.get("/health")
async def health():
    """Detailed health check."""
    from database import _pool
    pool_status = "disconnected"
    if _pool is not None:
        try:
            pool_status = "connected" if _pool.size > 0 else "empty"
        except Exception:
            pool_status = "error"
    return {
        "status": "healthy",
        "database": pool_status,
    }
