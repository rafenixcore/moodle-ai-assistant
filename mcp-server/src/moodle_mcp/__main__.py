"""Entry point: python -m moodle_mcp o el script moodle-mcp."""
from moodle_mcp.server import register_all


def main():
    mcp = register_all()
    mcp.run()


if __name__ == "__main__":
    main()
