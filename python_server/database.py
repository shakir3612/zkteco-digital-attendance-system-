"""
Async MySQL database connection pool using aiomysql.
Provides connection pool lifecycle management and a helper to get connections.
"""

import aiomysql
import logging
from typing import Optional

from config import DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME, DB_POOL_SIZE

logger = logging.getLogger(__name__)

# Global connection pool
_pool: Optional[aiomysql.Pool] = None


async def init_db_pool():
    """Initialize the database connection pool. Called on app startup."""
    global _pool
    try:
        _pool = await aiomysql.create_pool(
            host=DB_HOST,
            port=DB_PORT,
            user=DB_USER,
            password=DB_PASSWORD,
            db=DB_NAME,
            minsize=2,
            maxsize=DB_POOL_SIZE,
            autocommit=True,
            charset="utf8mb4",
            cursorclass=aiomysql.DictCursor,
        )
        logger.info(f"Database pool initialized: {DB_HOST}:{DB_PORT}/{DB_NAME}")
    except Exception as e:
        logger.error(f"Failed to initialize database pool: {e}")
        raise


async def close_db_pool():
    """Close the database connection pool. Called on app shutdown."""
    global _pool
    if _pool:
        _pool.close()
        await _pool.wait_closed()
        _pool = None
        logger.info("Database pool closed")


async def get_db_connection():
    """
    Get a connection from the pool.
    Usage:
        async with get_db_connection() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT ...")
                result = await cur.fetchall()
    """
    if _pool is None:
        raise RuntimeError("Database pool not initialized. Call init_db_pool() first.")
    return _pool.acquire()


class DatabaseConnection:
    """Context manager for database connections with automatic release."""

    def __init__(self):
        self.conn = None

    async def __aenter__(self):
        if _pool is None:
            raise RuntimeError("Database pool not initialized.")
        self.conn = await _pool.acquire()
        return self.conn

    async def __aexit__(self, exc_type, exc_val, exc_tb):
        if self.conn:
            _pool.release(self.conn)
        return False


def get_db():
    """Get a DatabaseConnection context manager instance."""
    return DatabaseConnection()
