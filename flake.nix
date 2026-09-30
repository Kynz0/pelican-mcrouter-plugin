{
  description = "Pelican mc-router plugin development and disposable compatibility harness";
  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
  outputs = { self, nixpkgs }:
    let
      systems = [ "x86_64-linux" "aarch64-linux" "aarch64-darwin" ];
      eachSystem = nixpkgs.lib.genAttrs systems;
    in {
      devShells = eachSystem (system:
        let pkgs = nixpkgs.legacyPackages.${system}; in {
          default = pkgs.mkShell {
            packages = with pkgs; [ php84 php84Packages.composer python3 git curl unzip zip go nodejs yarn openssh ];
          };
        } // pkgs.lib.optionalAttrs pkgs.stdenv.hostPlatform.isLinux {
          browser = pkgs.mkShell {
            inputsFrom = [ self.devShells.${system}.default ];
            packages = [ pkgs.chromium ];
            shellHook = ''export PLAYWRIGHT_CHROMIUM_EXECUTABLE=${pkgs.chromium}/bin/chromium'';
          };
        });
      apps = eachSystem (system:
        let
          pkgs = nixpkgs.legacyPackages.${system};
          runner = name: script: {
            type = "app";
            program = "${pkgs.writeShellScriptBin name ''
              export PATH=${pkgs.lib.makeBinPath [ pkgs.php84 pkgs.python3 pkgs.git pkgs.curl pkgs.zip pkgs.openssh ]}:$PATH
              exec bash ${self}/scripts/${script} "$@"
            ''}/bin/${name}";
          };
        in {
          test = runner "test" "test.sh";
          dev = runner "dev" "dev.sh";
        });
    };
}
