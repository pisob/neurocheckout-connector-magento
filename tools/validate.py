#!/usr/bin/env python3
"""Local lint and isolated checks; no store, database or Cloud connection."""
import pathlib
import json
import re
import subprocess
import sys
import xml.etree.ElementTree as ET

root = pathlib.Path(__file__).resolve().parents[1]
module = root / "app/code/NeuroCheckout/Connector"
version = json.loads((module / "composer.json").read_text())["version"]
if ET.parse(module / "etc/module.xml").find("module").get("setup_version") != version:
    raise RuntimeError("Magento setup version must match Composer")
client_version = re.search(r"CONNECTOR_VERSION\s*=\s*'([^']+)'", (module / "Model/Http/SecureHttpClient.php").read_text()).group(1)
if client_version != version:
    raise RuntimeError("Reported connector version must match installed package metadata")
readme = (root / "README.md").read_text()
for expected in [f"The current installable connector is **v{version}**", f"/releases/tag/v{version}",
                 f"/releases/download/v{version}/neurocheckout-connector-magento-{version}.zip"]:
    if expected not in readme:
        raise RuntimeError("README download instructions must match module version: " + expected)
files = [p for p in root.rglob("*") if p.is_file() and ".git" not in p.relative_to(root).parts]
for p in files:
    if p.is_symlink():
        raise RuntimeError("Symlink forbidden: " + str(p.relative_to(root)))
    if p.suffix == ".php":
        result = subprocess.run(["php", "-l", str(p)], capture_output=True, text=True, timeout=30)
        if result.returncode:
            sys.exit(result.stdout + result.stderr)
for test in ["tests/source_storage_key_test.php", "tests/source_key_rotation_test.php", "tests/customer_prices_protocol_test.php", "tests/customer_product_prices_test.php", "tests/product_availability_test.php", "tests/product_presentation_test.php", "tests/automatic_binding_test.php", "tests/security_boundaries.php", "tests/controller_compatibility_test.php", "tests/api_connection_test.php", "tests/scheduler_health_test.php", "tests/traffic_cron_test.php", "tests/traffic_cron_observer_test.php"]:
    subprocess.run(["php", str(root / test)], cwd=root, check=True, timeout=60)
subprocess.run(["php", str(root / "tests/recovery_safety_test.php")], cwd=root, check=True, timeout=60)
subprocess.run(["php", str(root / "tests/webapi_payload_test.php")], cwd=root, check=True, timeout=60)
subprocess.run(["php", str(root / "tests/customer_carts_protocol_test.php")], cwd=root, check=True, timeout=60)
print("All PHP files linted; isolated tests passed. Real platform integration is not covered.")

subprocess.run(["php", str(root / "tests/history_index_protocol_test.php")], cwd=root, check=True, timeout=60)
