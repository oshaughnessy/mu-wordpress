# mu-wordpress

Run WordPress on Amazon ECS and RDS with mu

## Overview

We can use [mu](https://getmu.io) to run the official
the official [WordPress Docker image](https://hub.docker.com/r/_/wordpress/)
in [Amazon's EC2 Container Service](https://aws.amazon.com/ecs/),
fronted by an [Application Load Balancer](https://aws.amazon.com/elasticloadbalancing/applicationloadbalancer/),
backed by an [RDS Aurora database](https://aws.amazon.com/rds/aurora/),
and deployed with [CodeBuild](https://aws.amazon.com/codebuild/)
and [CodePipeline](https://aws.amazon.com/codepipeline/).
Mu does it all for you through CloudFormation using a pair of simple YAML files.

## In brief

Clone [this repository](https://github.com/stelligent/mu-wordpress):

    git clone https://github.com/stelligent/mu-wordpress
    cd mu-wordpress

Set your AWS region if you want to use something other than the default,
`us-east-1`:

    export AWS_REGION=us-west-2

Start up your pipeline, which will deploy to 2 environments, "dev" and
"prod":

    mu pipeline up

Mu will ask you for a GitHub token. CodePipeline uses it to watch your
repo for changes so that it can automatically deploy them.
[Create a new token](https://github.com/settings/tokens) in your own
GitHub account and grant it the "admin:repo_hook" and "admin" permissions.
Save it somewhere, like [a nice password manager](https://1password.com).
Enter it when mu asks for it. (But don't give it to anything else! ;^)


## References:

* https://getmu.io
* https://stelligent.com/category/mu/
* https://hub.docker.com/r/_/wordpress/
* https://www.sitepoint.com/how-to-use-the-official-docker-wordpress-image/a

