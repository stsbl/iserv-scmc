/^Defaults:www-data env_keep += "ARG .*"$/a\
Defaults:www-data env_keep += "SCMC_ACT SCMC_MASTERPW SCMC_NEWMASTERPW SCMC_OLDMASTERPW SCMC_SESSIONTOKEN SCMC_SESSIONPW SCMC_USERPW"\
Defaults:scmcauthd env_keep += "SESSPW SCMC_SESSIONPW SCMC_SALT"
