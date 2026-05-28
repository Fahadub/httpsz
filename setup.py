"""Setup script for HTTPSZ."""
from setuptools import setup, find_packages

with open("README.md", "r", encoding="utf-8") as fh:
    long_description = fh.read()

setup(
    name="httpsz",
    version="2.1.0",
    author="HTTPSZ Contributors",
    description="Real HTTPS Security Scanner with CT, OCSP, and PQC",
    long_description=long_description,
    long_description_content_type="text/markdown",
    url="https://github.com/Fahadub/httpsz",
    packages=find_packages(exclude=["tests", "examples"]),
    classifiers=[
        "Development Status :: 4 - Beta",
        "Intended Audience :: Developers",
        "Topic :: Security",
        "License :: OSI Approved :: MIT License",
        "Programming Language :: Python :: 3",
    ],
    python_requires=">=3.8",
    install_requires=[
        "cryptography>=41.0.0",
    ],
    extras_require={
        "dev": ["pytest>=7.0.0", "black>=23.0.0", "mypy>=1.0.0"],
    },
    entry_points={
        "console_scripts": ["httpsz=httpsz.cli:main"],
    },
)
