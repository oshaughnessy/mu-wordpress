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

[Fork](https://help.github.com/articles/fork-a-repo/)
https://github.com/stelligent/mu-wordpress into your own GitHub account,
and then clone it to your workstation:

    git clone _your_clone_of_mu-wordpress_
    cd mu-wordpress

Why do all that instead of just cloning? You don't technically need to
fork the Stelligent mu-wordpress repo unless you want to follow its
changes, but it's a convenient way to get a copy in your GitHub account.
CodePipeline is going to watch _your_ repo for changes, which will give
you the power-user convenience of just pushing your code to trigger
updates in your WordPress deployment. Infrastructure as Code, amiright?

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

Watch your pipeline get deployed:

    mu pipeline logs -f



    mu service logs dev -f
    mu env logs dev -f

"dev" is the name of the first environment in your pipeline. Run "
the one that gets updated after you manually approve


## References:

* https://getmu.io
* https://stelligent.com/category/mu/
* https://hub.docker.com/r/_/wordpress/
* https://www.sitepoint.com/how-to-use-the-official-docker-wordpress-image/a

