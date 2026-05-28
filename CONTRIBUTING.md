# Contributing to HTTPSZ

Thanks for your interest in contributing!

## Development Setup

```bash
git clone https://github.com/Fahadub/httpsz.git
cd httpsz
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
pip install -e .
```

## Running Tests

```bash
pytest
pytest --cov=httpsz
```

## Code Style

We use `black` for formatting and `mypy` for type checking:

```bash
black httpsz tests
mypy httpsz
```

## Pull Request Process

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests for your changes
4. Ensure all tests pass (`pytest`)
5. Ensure code is formatted (`black`)
6. Commit with a clear message
7. Open a Pull Request

## Reporting Bugs

Open an issue with:
- Python version
- OS
- Full traceback
- Minimal reproduction steps

## Code of Conduct

Be respectful. We are all here to build something great together.
