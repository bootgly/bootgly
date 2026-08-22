<?php

// ! Deterministic test-only RSA material. Never use these published private
//   keys outside the native Security test suite.
return [
   'first' => [
      'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDygYJxYcrbVZKH
VpKjaJX1oVarqvGEHlA+C1jhlu3MDRaiwQeoeSnNvw0Fq4AprAicgiYCEKIxqvk+
hyOW/Ft2ASTaWg5J1Sf67tPqwINeXbq+Cc2b9h9Y3fJ60xN9zFdZKSbVmGV6z/A1
dQY/+p+j35sJQzIkFXk31XmOgacbzZm+BU7VbM5O2Dv6YkKKAj5sCkQQ8ySPF/EE
/M17KpTv07fM3UOKPmibszimp42BcovNpG20SLywDqswSEQRw/T2Pq5wDLvxd6ik
pFqCAoPmKTP7IQXC5GuZMJmMkQkuwOqWgJBPEU0n1ZaCJGiJHTcVFMQylSiBKt/E
V/4yCeEvAgMBAAECggEARW20tddkp5UBRYQQqX4I6PEPCkj/qm6vVIQVJ0j5veDF
aUVQdvhxcnlPNh9aqxOYx44vaYnvlb64ayFvnAuV99vt/CGqU5MWRi5YN650LfEx
xLSzzAIUCXJJuMZznyGApIM2nhJqg1XDFNrzNh//0n/zOByn31LSiJyyl40hFcEh
phDOeClqPtp73GW3fMzxP4nOBqAbelwjIznDBNAV900AhZiOJFe7VAPvGmrYXTg8
uEenj8x5RaKcgagQoVQpPwb8J5CW/y0sqc4Xeva+l70rAZrSeumORVqrCoc+wV2a
ySrQKhfx+mkdYEU//+AwF5vYLcx9SDhyYZG60S2+sQKBgQD6Y8c5NJ2UkGdveirD
WPr/uVsMUF5lkeoyHFYHggM0qbSarBQZreccHbpUOVssaMsqObI2J+qzlGrv8Csk
MLJPAwQxBpSeBL62c3u/mqTm/UEgnk4ZJWdrAsflEYTNJhibg1Se/LkYxIchNaX4
tTWWLtq0Tv1j3u713pfhuJOfxwKBgQD38IKKyd2PSY6n0GhX7lhGdG098NLu9Fe8
G1p8sqQOirWwozFhBjc9fL2fJvcDNkC5uVNEnOq3cqlrRDL6ZOKfpOHX2Yeizjne
hJEj+6arFEUhXUNLR651dlHHJI4sxYnQETLiA7aiBnVqFApn2Ci8zcchBFe4/9yr
1M2ghWgDWQKBgQCUmBRKGbSOzyfjW1/3cF381xZ2d1ed9XtD49cWO40FetUomYiQ
OMkXwXirtSIrd8FiPL1LMGMz0Zeo7yHbJ18aTtL0+U/He09m3aAJ9I96Wb+FyQzW
FYGLWyogAkaKrNobqFPWympajX8YMUtfDsNPblzydpIf69Rqa4A9P5m0TwKBgQDZ
8zgt3KnA7X5TkmZG9aPvuyTUkEA8Adql5r2yZC7HAbQZpDsh+R7SFDd0EgKNdkGL
gZfq9q11uXuoaXkOl2SHxZ8p6XTL7tD8BDi6Ets+BEGIxL0FDewUIYBduIqqXLN6
jcPW3kDLSTYpm5hSFLgq0BE9ut2KKJDJE/X2J495cQKBgFwcHy2yPDvqLGG8MvRf
KLG2pC6GO/kTIH5ToqUHOOLvyV5vsLIiw98Nqo3R6aIu03ctVRdlKIXSZ11epqWd
uW3ggKyY2zp7Nkjy0UM+nLpF66EwUy39yZ19ugFXNIe7TpXRV6Cv3rSMyx0I+zYt
h+xTmahuN8g1x+KJNTPrMJ9q
-----END PRIVATE KEY-----
PEM,
      'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA8oGCcWHK21WSh1aSo2iV
9aFWq6rxhB5QPgtY4ZbtzA0WosEHqHkpzb8NBauAKawInIImAhCiMar5Pocjlvxb
dgEk2loOSdUn+u7T6sCDXl26vgnNm/YfWN3yetMTfcxXWSkm1Zhles/wNXUGP/qf
o9+bCUMyJBV5N9V5joGnG82ZvgVO1WzOTtg7+mJCigI+bApEEPMkjxfxBPzNeyqU
79O3zN1Dij5om7M4pqeNgXKLzaRttEi8sA6rMEhEEcP09j6ucAy78XeopKRaggKD
5ikz+yEFwuRrmTCZjJEJLsDqloCQTxFNJ9WWgiRoiR03FRTEMpUogSrfxFf+Mgnh
LwIDAQAB
-----END PUBLIC KEY-----
PEM,
      'jwk' => [
         'kty' => 'RSA',
         'alg' => 'RS256',
         'use' => 'sig',
         'kid' => 'jwt-test-first',
         'n' => '8oGCcWHK21WSh1aSo2iV9aFWq6rxhB5QPgtY4ZbtzA0WosEHqHkpzb8NBauAKawInIImAhCiMar5PocjlvxbdgEk2loOSdUn-u7T6sCDXl26vgnNm_YfWN3yetMTfcxXWSkm1Zhles_wNXUGP_qfo9-bCUMyJBV5N9V5joGnG82ZvgVO1WzOTtg7-mJCigI-bApEEPMkjxfxBPzNeyqU79O3zN1Dij5om7M4pqeNgXKLzaRttEi8sA6rMEhEEcP09j6ucAy78XeopKRaggKD5ikz-yEFwuRrmTCZjJEJLsDqloCQTxFNJ9WWgiRoiR03FRTEMpUogSrfxFf-MgnhLw',
         'e' => 'AQAB',
      ],
      'body' => '{"keys":[{"kty":"RSA","alg":"RS256","use":"sig","kid":"jwt-test-first","n":"8oGCcWHK21WSh1aSo2iV9aFWq6rxhB5QPgtY4ZbtzA0WosEHqHkpzb8NBauAKawInIImAhCiMar5PocjlvxbdgEk2loOSdUn-u7T6sCDXl26vgnNm_YfWN3yetMTfcxXWSkm1Zhles_wNXUGP_qfo9-bCUMyJBV5N9V5joGnG82ZvgVO1WzOTtg7-mJCigI-bApEEPMkjxfxBPzNeyqU79O3zN1Dij5om7M4pqeNgXKLzaRttEi8sA6rMEhEEcP09j6ucAy78XeopKRaggKD5ikz-yEFwuRrmTCZjJEJLsDqloCQTxFNJ9WWgiRoiR03FRTEMpUogSrfxFf-MgnhLw","e":"AQAB"}]}',
   ],
   'second' => [
      'private' => <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCkodNPS51UswQK
52dmtMZ0wtqeSd3WgTjGuWxwPwW2RWEM/ulttr4ixWCmgC9NQdXU3R2r53+57mKb
6kxcaGQAfQFJNEx8PYZdrzn35tGa4hLzkNa4b/5EvvnDkCrMytyAGM+qbM8sSzm7
JfbpczMwvDKNBTGaHZEJ6CTZH5QeZSvmvgwTwWDIK2ZGNGQtA2+TUpxjtqmqpE83
m6s9m2IBPp05wKIgryk+lWgVHXdVplPy+IdvN1LW818DDyG47V6nCy35YDL5GgAz
Fe3GQJox532EafnjOOBQFucb48zkkwexqfAimucN0MstFAR7E4lWCxQlNwQKiBgB
6RpDZR8VAgMBAAECggEASgnzD9LIP1rA4yx9jq2XINSu4AgFSd5Ui97BG1vkdDwQ
cPlFPo+0Q8vzcv3sB/knMvN8UB6tDQ/d7hO5YseZzbRqOyNXkHpU7tYKomf+dQLe
FgbZ4hmPxxgCGIbCENbcwokl/5WuuFNec8GXoRare2vv6gbfb1mDrGpoA/OVN/N7
fKjeNctCNDfYC2QxoL5NKCsfoEOi+EfXrippYsy96BZ7C6dHOGphqJI1zlpWIHXC
5esxHF/GFP5O3xhbn4f9bUqw5an2leMkFaxvqQEjzA85mOAlqWzp0lkK9D6O6Ojf
laLxUfjAuGm0Fh7dT9jpfA+wCavsePxZtinWyHpukwKBgQDiMVRL0ycrkXWkwElH
X7cK1XQasQ0v6oygFJWoJxsVIduM7J+FX+LqKpYtGMmnd7XNhmduHxw9/pyxmX+4
SeLo/IUpH4SbOFX8kNQb+qK3NpLdpOM2f3bZ8X1BlFscA/8a06Vevw46n+X9Rckw
0kgQRrQAambusbZI2K25I1aEBwKBgQC6U7v0SHV8BTJDoA1DyMK+CTYgSJxgGzmo
qnSdvq6+4iv2315q5bUGPAkB3zCS8UDaMUJ0DXTgEoaHSXkVS7Fxo0dPdohrh9vg
6xz3nR1okk1TvLdQ/SFsQXOxBuJz4bjOGihqGpe/CYThlAok+3xY1ORhcUnC1TtR
YMOTucsVAwKBgB3PPHnFuSrPv75XrRCf96KQ4P1HiiJfeer664I+rR4K2UFoSdms
+l28ARCubJ0KdMZCSU1FAlbVQFdTkHZ8HlBwPyhdQ/+k3AguGhYZ4OneYlqdiWg8
QjCU19oVsDAwAqXJcMjcywZE3m2TjeFGRUMH3l8Tpr8cYpaVH8f/dT5vAoGBALgm
N5Vn440OCa5iSZnbmfR9YBwqBzrIzYSP6q9YnJYVLARSoJsfqXie7vwFnJktjL3Y
f2f5QLCQPpsIl33fkGDSUZEMgilcXYh+deOXSVBnf7spwTdu5ZB7Y57rQfXreV1g
5t5up9jrzIOxbxE755pX1dskPxUq7vQvoTuoHTkHAoGBAIhLPYECMzojVWdN0jZ3
zwPquqRkUgxL3O5bnQaK2ftO3gYTZNcA/vKCnYGV63YEw6JkOBXH0/6S48hHeH8u
Xyk8hN9BMWt5qnUorKMVDHlj3zuYWKagyIrImoaKo9uc7r3yiB3j0gVWONE4+6DJ
o/Kdj2vpdDjmoz67JonXhatj
-----END PRIVATE KEY-----
PEM,
      'public' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEApKHTT0udVLMECudnZrTG
dMLanknd1oE4xrlscD8FtkVhDP7pbba+IsVgpoAvTUHV1N0dq+d/ue5im+pMXGhk
AH0BSTRMfD2GXa859+bRmuIS85DWuG/+RL75w5AqzMrcgBjPqmzPLEs5uyX26XMz
MLwyjQUxmh2RCegk2R+UHmUr5r4ME8FgyCtmRjRkLQNvk1KcY7apqqRPN5urPZti
AT6dOcCiIK8pPpVoFR13VaZT8viHbzdS1vNfAw8huO1epwst+WAy+RoAMxXtxkCa
Med9hGn54zjgUBbnG+PM5JMHsanwIprnDdDLLRQEexOJVgsUJTcECogYAekaQ2Uf
FQIDAQAB
-----END PUBLIC KEY-----
PEM,
      'jwk' => [
         'kty' => 'RSA',
         'alg' => 'RS256',
         'use' => 'sig',
         'kid' => 'jwt-test-second',
         'n' => 'pKHTT0udVLMECudnZrTGdMLanknd1oE4xrlscD8FtkVhDP7pbba-IsVgpoAvTUHV1N0dq-d_ue5im-pMXGhkAH0BSTRMfD2GXa859-bRmuIS85DWuG_-RL75w5AqzMrcgBjPqmzPLEs5uyX26XMzMLwyjQUxmh2RCegk2R-UHmUr5r4ME8FgyCtmRjRkLQNvk1KcY7apqqRPN5urPZtiAT6dOcCiIK8pPpVoFR13VaZT8viHbzdS1vNfAw8huO1epwst-WAy-RoAMxXtxkCaMed9hGn54zjgUBbnG-PM5JMHsanwIprnDdDLLRQEexOJVgsUJTcECogYAekaQ2UfFQ',
         'e' => 'AQAB',
      ],
      'body' => '{"keys":[{"kty":"RSA","alg":"RS256","use":"sig","kid":"jwt-test-second","n":"pKHTT0udVLMECudnZrTGdMLanknd1oE4xrlscD8FtkVhDP7pbba-IsVgpoAvTUHV1N0dq-d_ue5im-pMXGhkAH0BSTRMfD2GXa859-bRmuIS85DWuG_-RL75w5AqzMrcgBjPqmzPLEs5uyX26XMzMLwyjQUxmh2RCegk2R-UHmUr5r4ME8FgyCtmRjRkLQNvk1KcY7apqqRPN5urPZtiAT6dOcCiIK8pPpVoFR13VaZT8viHbzdS1vNfAw8huO1epwst-WAy-RoAMxXtxkCaMed9hGn54zjgUBbnG-PM5JMHsanwIprnDdDLLRQEexOJVgsUJTcECogYAekaQ2UfFQ","e":"AQAB"}]}',
   ],
];
