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

    yield

    # SHUTDOWN
    logger.info("Shutting down...")
    await close_db_pool()
    logger.info("Server stopped")


# =============================================================================
# CREATE APP
# =============================================================================
app = FastAPI(
    title="ZKTeco Attendance Server",
    description="Device communication server for ZKTeco SpeedFace V5L using PUSH/ADMS protocol",
    version="1.0.0",
    lifespan=lifespan,
)

# CORS middleware (allow PHP dashboard to call internal APIs)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# =============================================================================
# REGISTER ROUTES
# =============================================================================
app.include_router(iclock_router)


# =============================================================================
# HEALTH CHECK
# =============================================================================
@app.get("/")
async def root():
    """Server health check endpoint."""
    return {
        "status": "running",
        "service": "ZKTeco Attendance Server",
        "version": "1.0.0",
        "timezone": SERVER_TIMEZONE,
    }


@app.get("/health")
async def health():
    """Detailed health check."""
    from database import _pool
    return {
        "status": "healthy",
        "database": "connected" if _pool and not _pool._closed else "disconnected",
    }
