CWD := $(shell pwd)
PHP_INCLUDE_PATH := $(shell echo '<?php echo get_include_path();'|php)
PHP := 'php -d include_path="$(CWD):$(PHP_INCLUDE_PATH)"'
PROVE := prove -r --exec $(PHP)

default:

.PHONY: test install

install:
	pear channel-discover onlinebuddies.github.com/pear ; pear install package.xml

uninstall:
	pear uninstall OnlineBuddies/OLB_PDO

test:
	$(PROVE) test

test-cover:
	rm -rf tmp/test coverage
	TEST_COVERAGE=1 $(PROVE) test
	phpcov --merge --html coverage tmp/test/coverage
	rm -rf tmp/test

test-verbose:
	$(PROVE) -v test
